<?php
/**
 * Staff Billing Desk — NexusFix (Professional Edition)
 * Manages quote approval and invoice generation for repair requests.
 * Features: Tabbed views (Pending Quotes, Approved Quotes, Billed), approve/reject quotes,
 *           invoice generation, problem summaries, parts listing, attachments.
 * Tabs:
 * - Pending Quotes: Requests with invoices.quote_status = 'Pending'
 * - Approved Quotes: Requests with invoices.quote_status = 'Approved'
 * - Ready for Billing / Billed: Requests with repair_requests.status = 'Billed'
 */
declare(strict_types=1);
require_once __DIR__.'/../../includes/bootstrap.php';
require_role('staff', 'admin');

$note = null;
$errors = [];

// --- Helper Functions (Copied from repair.php) ---
function get_invoice($rid) {
    $s = db()->prepare("SELECT * FROM invoices WHERE request_id=?");
    $s->execute([$rid]);
    return $s->fetch();
}

function parts_for($rid) {
    $s = db()->prepare("SELECT * FROM request_parts WHERE request_id=? ORDER BY id DESC");
    $s->execute([$rid]);
    return $s->fetchAll();
}

function history_for($rid) {
    $s = db()->prepare("SELECT h.*, u.name as user_name
                        FROM request_status_history h
                        LEFT JOIN users u ON u.id = h.changed_by
                        WHERE h.request_id=?
                        ORDER BY h.id DESC");
    $s->execute([$rid]);
    return $s->fetchAll();
}

function add_history($rid, $status, $note, $userId) {
    $s = db()->prepare("INSERT INTO request_status_history (request_id, status, note, changed_by) VALUES (?,?,?,?)");
    return $s->execute([$rid, $status, $note, $userId]);
}

function get_request_attachments(int $request_id): array {
    $stmt = db()->prepare("SELECT file_path, file_type FROM request_attachments WHERE request_id = ? ORDER BY uploaded_at ASC");
    $stmt->execute([$request_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function is_image_type(string $file_type): bool {
    $image_types = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    return in_array(strtolower($file_type), $image_types);
}

function is_video_type(string $file_type): bool {
    $video_types = ['mp4', 'mov', 'avi', 'mkv', 'webm'];
    return in_array(strtolower($file_type), $video_types);
}

function get_file_url(string $file_path): string {
    $base = rtrim(dirname(base_url()), '/');
    return $base . '/' . ltrim($file_path, '/');
}

// --- POST Actions ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf'] ?? '')) {
        $errors['csrf'] = 'Invalid session token. Please refresh the page.';
    } else {
        $act = $_POST['act'] ?? '';
        $rid = (int)($_POST['rid'] ?? 0);

        // Load request and invoice
        $reqStmt = db()->prepare("SELECT * FROM repair_requests WHERE id=?");
        $reqStmt->execute([$rid]);
        $req = $reqStmt->fetch();
        $invoice = get_invoice($rid);

        if (!$req || !$invoice) {
            $errors['req'] = 'Request or invoice not found.';
        } else {
            $userId = $_SESSION['user']['id'];
            $pdo = db();

            // Approve Quote
            if ($act === 'approve_quote' && $invoice['quote_status'] === 'Pending') {
                $pdo->beginTransaction();
                try {
                    $s = $pdo->prepare("UPDATE invoices SET quote_status='Approved', approved_by=? WHERE id=?");
                    $s->execute([$userId, $invoice['id']]);
                    add_history($rid, $req['status'], 'Quote approved by Billing Desk', $userId);
                    $pdo->commit();
                    $note = 'Quote approved successfully.';
                } catch (Exception $e) {
                    $pdo->rollBack();
                    $errors['approve'] = 'Failed to approve quote. Please try again.';
                    error_log("Quote Approval Error (RID: $rid): " . $e->getMessage());
                }
            }

            // Reject Quote
            if ($act === 'reject_quote' && $invoice['quote_status'] === 'Pending') {
                $rejectReason = trim($_POST['reject_reason'] ?? '');
                $pdo->beginTransaction();
                try {
                    $s = $pdo->prepare("UPDATE invoices SET quote_status='Rejected' WHERE id=?");
                    $s->execute([$invoice['id']]);
                    $historyNote = 'Quote rejected by Billing Desk' . ($rejectReason ? ': ' . $rejectReason : '');
                    add_history($rid, $req['status'], $historyNote, $userId);
                    $pdo->commit();
                    $note = 'Quote rejected successfully.';
                } catch (Exception $e) {
                    $pdo->rollBack();
                    $errors['reject'] = 'Failed to reject quote. Please try again.';
                    error_log("Quote Rejection Error (RID: $rid): " . $e->getMessage());
                }
            }

            // Generate Invoice
            if ($act === 'generate_invoice' && $req['status'] === 'Billed' && $invoice['status'] !== 'Finalized') {
                $pdo->beginTransaction();
                try {
                    // Dummy calculations (extend with actual pricing logic when available)
                    $subtotal = 0.00; // Placeholder: Sum parts prices (requires price field in request_parts)
                    $tax_rate = $invoice['tax_rate'] ?? 0.13; // Use stored tax rate
                    $tax_amount = $subtotal * $tax_rate;
                    $total = $subtotal + $tax_amount;

                    $s = $pdo->prepare("UPDATE invoices SET status='Finalized', subtotal=?, tax_amount=?, total=?, billed_at=NOW() WHERE id=?");
                    $s->execute([$subtotal, $tax_amount, $total, $invoice['id']]);
                    add_history($rid, 'Billed', 'Final invoice generated', $userId);
                    $pdo->commit();
                    $note = 'Invoice generated successfully.';
                } catch (Exception $e) {
                    $pdo->rollBack();
                    $errors['invoice'] = 'Failed to generate invoice. Please try again.';
                    error_log("Invoice Generation Error (RID: $rid): " . $e->getMessage());
                }
            }
        }
    }
}

// --- Queries for View ---
$pendingQuoteRows = db()->query("
    SELECT rr.*, u.name cust_name, u.email cust_email, u.phone cust_phone,
           i.id as invoice_id, i.quote_status
    FROM repair_requests rr
    JOIN users u ON u.id = rr.customer_id
    JOIN invoices i ON i.request_id = rr.id
    WHERE i.quote_status = 'Pending' AND rr.status = 'In Repair'
    ORDER BY rr.id DESC
")->fetchAll();

$approvedQuoteRows = db()->query("
    SELECT rr.*, u.name cust_name, u.email cust_email, u.phone cust_phone,
           i.id as invoice_id, i.quote_status, i.approved_by, i.created_at as quote_approved_at
    FROM repair_requests rr
    JOIN users u ON u.id = rr.customer_id
    JOIN invoices i ON i.request_id = rr.id
    WHERE i.quote_status = 'Approved' AND rr.status = 'In Repair'
    ORDER BY rr.id DESC
")->fetchAll();

$billedRows = db()->query("
    SELECT rr.*, u.name cust_name, u.email cust_email, u.phone cust_phone,
           i.id as invoice_id, i.quote_status, i.status as invoice_status, 
           i.subtotal, i.tax_amount, i.total, i.billed_at
    FROM repair_requests rr
    JOIN users u ON u.id = rr.customer_id
    JOIN invoices i ON i.request_id = rr.id
    WHERE rr.status = 'Billed'
    ORDER BY rr.id DESC
")->fetchAll();

// Add attachments to rows
function add_attachments(&$rows) {
    foreach ($rows as &$row) {
        $row['attachments'] = get_request_attachments((int)$row['id']);
    }
    unset($row);
}
add_attachments($pendingQuoteRows);
add_attachments($approvedQuoteRows);
add_attachments($billedRows);
?>

<!doctype html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Billing Desk — NexusFix</title>
    <link rel="stylesheet" href="../../assets/css/styles.css">
    <style>
        /* Same CSS as repair.php for consistency */
        :root {
            --bg: #0b0c0f;
            --card: #101218;
            --text: #e8eaf0;
            --muted: #a6adbb;
            --border: #1f2430;
            --field-border: #2a3242;
            --primary: #60a5fa;
            --accent: #6ee7b7;
            --danger: #f87171;
            --success: #34d399;
            --warning: #fbbf24;
            --info: #93c5fd;
            --shadow: 0 10px 30px rgba(0,0,0,.35);
            --shadow-sm: 0 4px 12px rgba(0,0,0,.18);
            --radius: 16px;
            --transition: all 0.2s ease;
        }
        [data-theme="light"] {
            --bg: #f7f8fb;
            --card: #ffffff;
            --text: #0b0c0f;
            --muted: #5b6172;
            --border: #e5e7eb;
            --field-border: #cbd5e1;
            --shadow: 0 10px 25px rgba(15,23,42,.08);
            --shadow-sm: 0 4px 12px rgba(0,0,0,.1);
        }
        body {
            background: var(--bg);
            color: var(--text);
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            line-height: 1.5;
            margin: 0;
            padding: 0;
            transition: background 0.3s ease, color 0.3s ease;
        }
        main {
            max-width: 1200px;
            margin: 2rem auto;
            padding: 1rem;
        }
        h1, h2, h3, h4 {
            font-weight: 600;
            margin-top: 0;
        }
        h1 { font-size: 1.75rem; margin-bottom: 1.5rem; }
        h2 { font-size: 1.25rem; }
        h3 { font-size: 1.1rem; }
        h4 { font-size: 1rem; margin-top: 1rem; margin-bottom: 0.5rem; }
        .card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 1.25rem;
            margin-bottom: 1rem;
            box-shadow: var(--shadow-sm);
            transition: var(--transition);
        }
        .card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow);
        }
        .card-header {
            display: flex;
            flex-wrap: wrap;
            justify-content: space-between;
            gap: 1rem;
            align-items: flex-start;
            margin-bottom: 1rem;
        }
        .tabs {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            margin-bottom: 1.5rem;
        }
        .tab-btn {
            padding: 0.5rem 1rem;
            border-radius: 999px;
            border: 1px solid var(--field-border);
            background: var(--card);
            color: var(--text);
            font-weight: 500;
            cursor: pointer;
            transition: var(--transition);
        }
        .tab-btn:hover {
            background: var(--field-border);
        }
        .tab-btn.active {
            background: linear-gradient(90deg, #6366f1, #ec4899, #f59e0b);
            color: #fff;
            border: none;
            box-shadow: 0 2px 6px rgba(0,0,0,.15);
        }
        .tab-content { display: none; }
        .tab-content.active { display: block; }
        .chip {
            display: inline-flex;
            align-items: center;
            padding: 0.25rem 0.75rem;
            border-radius: 999px;
            font-size: 0.8rem;
            font-weight: 500;
        }
        .status-inrepair    { background: rgba(59, 130, 246, 0.15); color: var(--primary); border: 1px solid rgba(59, 130, 246, 0.3); }
        .status-billed      { background: rgba(245, 158, 11, 0.15); color: var(--warning); border: 1px solid rgba(245, 158, 11, 0.3); }
        .status-pending     { background: rgba(163, 163, 163, 0.15); color: var(--muted); border: 1px solid rgba(163, 163, 163, 0.3); }
        .status-approved    { background: rgba(16, 185, 129, 0.15); color: var(--success); border: 1px solid rgba(16, 185, 129, 0.3); }
        .status-rejected    { background: rgba(248, 113, 113, 0.15); color: var(--danger); border: 1px solid rgba(248, 113, 113, 0.3); }
        .status-finalized   { background: rgba(16, 185, 129, 0.15); color: var(--success); border: 1px solid rgba(16, 185, 129, 0.3); }
        .btn {
            padding: 0.5rem 1rem;
            border-radius: 12px;
            border: 1px solid transparent;
            background: rgba(255,255,255,.06);
            color: var(--text);
            font-weight: 500;
            font-size: 0.9rem;
            cursor: pointer;
            text-decoration: none;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            white-space: nowrap;
        }
        .btn:hover {
            background: rgba(255,255,255,.1);
        }
        .btn.success {
            background: var(--success);
            color: white;
        }
        .btn.success:hover {
            background: #2cbe8a;
            transform: translateY(-1px);
        }
        .btn.danger {
            background: var(--danger);
            color: white;
        }
        .btn.danger:hover {
            background: #e53e3e;
            transform: translateY(-1px);
        }
        input, select, textarea {
            width: 100%;
            padding: 0.6rem 0.8rem;
            border: 1px solid var(--field-border);
            border-radius: 12px;
            background: var(--card);
            color: var(--text);
            font-size: 0.95rem;
            transition: var(--transition);
        }
        input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(96, 165, 250, 0.2);
        }
        .form-inline {
            display: flex;
            gap: 0.5rem;
            align-items: flex-end;
        }
        .alert {
            padding: 1rem;
            border-radius: 12px;
            margin-bottom: 1.5rem;
            font-size: 0.95rem;
        }
        .alert.success {
            background: rgba(52, 211, 153, 0.15);
            border: 1px solid rgba(52, 211, 153, 0.3);
            color: var(--success);
        }
        .alert.error {
            background: rgba(248, 113, 113, 0.15);
            border: 1px solid rgba(248, 113, 113, 0.3);
            color: var(--danger);
        }
        .alert ul {
            margin: 0.5rem 0 0 1.2rem;
            padding: 0;
        }
        .search-input {
            width: 100%;
            padding: 0.7rem 1rem;
            border: 1px solid var(--field-border);
            border-radius: 12px;
            background: var(--card);
            color: var(--text);
            font-size: 0.95rem;
            margin-bottom: 1.5rem;
        }
        .search-input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(96, 165, 250, 0.2);
        }
        .grid-2 { display: grid; gap: 1.5rem; }
        .grid-2 { grid-template-columns: 1fr; }
        @media (min-width: 992px) {
            .grid-2 { grid-template-columns: 2fr 1fr; }
        }
        .timeline {
            position: relative;
            padding-left: 1.5rem;
            margin-top: 1rem;
        }
        .timeline::before {
            content: '';
            position: absolute;
            left: 10px;
            top: 0;
            bottom: 0;
            width: 2px;
            background: var(--field-border);
        }
        .timeline-item {
            position: relative;
            margin-bottom: 1.25rem;
        }
        .timeline-dot {
            position: absolute;
            left: -1.6rem;
            top: 0.3rem;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            background: var(--primary);
            border: 2px solid var(--card);
            z-index: 1;
        }
        .timeline-header {
            display: flex;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 0.5rem;
            margin-bottom: 0.25rem;
        }
        .timeline-status {
            font-weight: 600;
        }
        .timeline-note {
            font-size: 0.9rem;
            color: var(--text);
        }
        .timeline-meta {
            display: flex;
            justify-content: space-between;
            font-size: 0.8rem;
            color: var(--muted);
        }
        .timeline-empty {
            color: var(--muted);
            font-style: italic;
            padding-left: 1rem;
        }
        .parts-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 0.5rem;
            font-size: 0.9rem;
        }
        .parts-table th {
            text-align: left;
            padding: 0.6rem 0.5rem;
            border-bottom: 1px solid var(--field-border);
            color: var(--muted);
            font-weight: 600;
            font-size: 0.85rem;
        }
        .parts-table td {
            padding: 0.6rem 0.5rem;
            border-bottom: 1px solid var(--field-border);
        }
        .parts-table tr:last-child td {
            border-bottom: none;
        }
        .section-title {
            margin-bottom: 0.75rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .highlight-box {
            background: rgba(251, 191, 36, 0.1);
            border: 1px solid rgba(251, 191, 36, 0.3);
            border-radius: 12px;
            padding: 1rem;
            margin-bottom: 1rem;
        }
        .highlight-box .section-title {
            color: var(--warning);
            margin-top: 0;
        }
        .problem-dropdown-trigger {
            font-size: 0.85rem;
            color: var(--muted);
            cursor: pointer;
            user-select: none;
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
        }
        .problem-dropdown-trigger:hover {
            color: var(--text);
            text-decoration: underline;
        }
        .problem-list-dropdown {
            list-style-type: none;
            padding: 0;
            margin: 0.5rem 0 0;
            background-color: var(--card);
            border: 1px solid var(--field-border);
            border-radius: 8px;
            box-shadow: var(--shadow-sm);
            max-height: 200px;
            overflow-y: auto;
            z-index: 10;
        }
        .problem-list-dropdown li {
            padding: 0.5rem 0.75rem;
            border-bottom: 1px dotted var(--field-border);
            font-size: 0.9rem;
        }
        .problem-list-dropdown li:last-child {
            border-bottom: none;
        }
        .problem-list-dropdown li:hover {
            background-color: var(--field-border);
        }
        .attachments-grid {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 0.75rem;
        }
        .attachment-item {
            position: relative;
            width: 80px;
            height: 80px;
            border-radius: 8px;
            overflow: hidden;
            border: 1px solid var(--field-border);
            background-color: var(--card);
            cursor: pointer;
        }
        .attachment-item img,
        .attachment-item video {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.2s ease;
        }
        .attachment-item:hover img,
        .attachment-item:hover video {
            transform: scale(1.05);
        }
        .attachment-item video {
            background: #111;
        }
        .attachment-placeholder {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 100%;
            height: 100%;
            background: var(--field-border);
            color: var(--muted);
            font-size: 0.6rem;
            font-weight: bold;
        }
        .attachment-filename {
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            background: rgba(0,0,0,0.7);
            color: white;
            font-size: 0.55rem;
            padding: 2px 4px;
            text-align: center;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .no-attachments {
            color: var(--muted);
            font-size: 0.9rem;
            font-style: italic;
            margin-top: 0.5rem;
        }
        .lightbox {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.9);
            z-index: 1000;
            justify-content: center;
            align-items: center;
            flex-direction: column;
        }
        .lightbox.active {
            display: flex;
        }
        .lightbox-content {
            max-width: 90vw;
            max-height: 85vh;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 10px 50px rgba(0,0,0,0.5);
        }
        .lightbox-content img,
        .lightbox-content video {
            max-width: 100%;
            max-height: 80vh;
            display: block;
        }
        .lightbox-close {
            position: absolute;
            top: 20px;
            right: 20px;
            width: 40px;
            height: 40px;
            border: none;
            background: rgba(255,255,255,0.2);
            color: white;
            font-size: 1.5rem;
            border-radius: 50%;
            cursor: pointer;
            backdrop-filter: blur(5px);
        }
        .lightbox-close:hover {
            background: rgba(255,255,255,0.3);
        }
        .lightbox-filename {
            margin-top: 10px;
            color: #aaa;
            font-size: 0.9rem;
        }
        .muted { color: var(--muted); }
        .text-sm { font-size: 0.9rem; }
        .text-xs { font-size: 0.8rem; }
        .font-medium { font-weight: 500; }
        .flex { display: flex; }
        .items-center { align-items: center; }
        .justify-between { justify-content: space-between; }
        .gap-2 { gap: 0.5rem; }
        .gap-4 { gap: 1rem; }
        .hidden { display: none; }
        .w-full { width: 100%; }
        .mb-4 { margin-bottom: 1rem; }
        .mt-4 { margin-top: 1rem; }
        .py-2 { padding-top: 0.5rem; padding-bottom: 0.5rem; }
        .mb-6 { margin-bottom: 1.5rem; }
        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after {
                animation-duration: 0.01ms !important;
                animation-iteration-count: 1 !important;
                transition-duration: 0.01ms !important;
            }
        }
    </style>
</head>

<body>
<?php require __DIR__.'/../../includes/header.php'; ?>

<main aria-labelledby="page-title">
    <div>
        <h1 id="page-title">Billing Desk</h1>
        <?php if ($note): ?>
            <div class="alert success" role="alert">
                <strong>Success:</strong> <?= e($note) ?>
            </div>
        <?php endif; ?>
        <?php if ($errors): ?>
            <div class="alert error" role="alert">
                <strong>Please fix the following:</strong>
                <ul>
                    <?php foreach ($errors as $key => $m): ?>
                        <?php if ($key !== 'csrf'): ?>
                            <li><?= e($m) ?></li>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </ul>
                <?php if (!empty($errors['csrf'])): ?>
                    <p style="margin-top: 0.5rem;"><?= e($errors['csrf']) ?></p>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Tabs -->
    <div class="tabs" role="tablist">
        <button class="tab-btn active" data-tab="pending-quotes" role="tab" aria-selected="true">📋 Pending Quotes</button>
        <button class="tab-btn" data-tab="approved-quotes" role="tab" aria-selected="false">🧾 Approved Quotes</button>
        <button class="tab-btn" data-tab="billed-requests" role="tab" aria-selected="false">💸 Ready for Billing / Billed</button>
    </div>

    <!-- Pending Quotes -->
    <section id="pending-quotes" class="tab-content active" role="tabpanel" aria-labelledby="tab-pending-quotes">
        <input type="text" class="search-input" placeholder="🔎 Search (ticket, device, customer)" data-target="list-pending-quotes" aria-label="Search Pending Quotes">
        <div id="list-pending-quotes">
            <?php foreach ($pendingQuoteRows as $r):
                $plist = parts_for($r['id']);
                $history = history_for($r['id']);
                $problemText = $r['problem'] ?? ($r['problem_reported'] ?? ($r['issue_description'] ?? ($r['notes'] ?? '')));
                $problemList = $problemText ? array_map('trim', explode(';', $problemText)) : [];
                $problemList = array_filter($problemList, fn($item) => !empty($item));
            ?>
            <article class="card searchable"
                data-ticket="<?= strtolower(e($r['ticket_code'])) ?>"
                data-device="<?= strtolower(e($r['device_type'])) ?>"
                data-brand="<?= strtolower(e($r['brand'] ?? '')) ?>"
                data-model="<?= strtolower(e($r['model'] ?? '')) ?>"
                data-customer="<?= strtolower(e($r['cust_name'])) ?>">
                <div class="card-header">
                    <div>
                        <div class="flex items-center gap-2 flex-wrap">
                            <h2><?= e($r['ticket_code']) ?></h2>
                            <span class="chip status-inrepair"><?= e($r['status']) ?></span>
                            <span class="chip status-pending">Quote #<?= e($r['invoice_id']) ?> — <?= e($r['quote_status']) ?></span>
                        </div>
                        <div class="text-sm muted mt-1">
                            <?= e($r['device_type']) ?><?= $r['brand'] ? ' · <span class="font-medium">' . e($r['brand']) . '</span>' : '' ?><?= $r['model'] ? ' · <span class="font-medium">' . e($r['model']) . '</span>' : '' ?>
                        </div>
                        <div class="text-sm muted mt-1">
                            Customer: <span class="font-medium"><?= e($r['cust_name']) ?></span> • <?= e($r['cust_email']) ?><?= $r['cust_phone'] ? ' • ' . e($r['cust_phone']) : '' ?>
                        </div>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <form method="post" style="display:inline;">
                            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="rid" value="<?= e($r['id']) ?>">
                            <button class="btn success" name="act" value="approve_quote">Approve Quote</button>
                        </form>
                        <form method="post" class="form-inline" style="display:inline;">
                            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="rid" value="<?= e($r['id']) ?>">
                            <input name="reject_reason" placeholder="Reason (optional)" aria-label="Reject reason">
                            <button class="btn danger" name="act" value="reject_quote" onclick="return confirm('Reject this quote?');">Reject</button>
                        </form>
                    </div>
                </div>

                <div class="grid-2">
                    <div>
                        <div class="highlight-box">
                            <h3 class="section-title">⚠️ Problem Reported</h3>
                            <?php if ($problemText): ?>
                                <?php if (!empty($problemList) && count($problemList) > 1): ?>
                                    <div class="text-sm mb-2"><?= nl2br(e($problemText)) ?></div>
                                    <div class="relative">
                                        <div class="problem-dropdown-trigger" onclick="toggleProblemList(this, '<?= e($r['id']) ?>')">
                                            Show individual issues <span id="dropdown-icon-<?= e($r['id']) ?>">▼</span>
                                        </div>
                                        <ul id="problem-list-<?= e($r['id']) ?>" class="problem-list-dropdown hidden">
                                            <?php foreach ($problemList as $issue): ?>
                                                <li><?= e($issue) ?></li>
                                            <?php endforeach; ?>
                                        </ul>
                                    </div>
                                <?php else: ?>
                                    <div class="text-sm"><?= nl2br(e($problemText)) ?></div>
                                <?php endif; ?>
                            <?php else: ?>
                                <div class="text-sm muted">No problem description provided.</div>
                            <?php endif; ?>
                        </div>

                        <div class="card">
                            <h3 class="section-title">🧰 Parts for Quote</h3>
                            <div class="overflow-x-auto">
                                <table class="parts-table">
                                    <thead>
                                        <tr>
                                            <th>Item</th>
                                            <th>Qty</th>
                                            <th>Unit</th>
                                            <th>Remarks</th>
                                            <!-- Future Enhancement: Add Price Column -->
                                            <!-- <th>Unit Price</th> <th>Total</th> -->
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($plist as $p): ?>
                                            <tr>
                                                <td><?= e($p['item']) ?></td>
                                                <td><?= e($p['qty']) ?></td>
                                                <td class="muted"><?= e($p['unit']) ?></td>
                                                <td class="muted"><?= e($p['remarks']) ?></td>
                                                <!-- Future Enhancement: Editable Price Fields -->
                                                <!--
                                                <td><input type="number" value="0.00" step="0.01" min="0"></td>
                                                <td class="muted">$0.00</td>
                                                -->
                                            </tr>
                                        <?php endforeach; ?>
                                        <?php if (!$plist): ?>
                                            <tr><td colspan="4" class="text-center muted py-2">No parts listed for quote.</td></tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                            <!-- Future Enhancement: Save Prices Button -->
                            <!--
                            <form method="post" class="mt-4">
                                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="rid" value="<?= e($r['id']) ?>">
                                <button class="btn primary" name="act" value="save_prices">Save Prices</button>
                            </form>
                            -->
                        </div>
                    </div>

                    <div>
                        <div class="card mb-4">
                            <h3 class="section-title">📎 Attachments</h3>
                            <?php if (empty($r['attachments'])): ?>
                                <span class="no-attachments">No files attached.</span>
                            <?php else: ?>
                                <div class="attachments-grid">
                                    <?php foreach ($r['attachments'] as $attachment): ?>
                                        <?php
                                            $file_path = $attachment['file_path'];
                                            $file_type = strtolower($attachment['file_type']);
                                            $full_url = get_file_url($file_path);
                                            $filename = basename($file_path);
                                            $is_image = is_image_type($file_type);
                                            $is_video = is_video_type($file_type);
                                        ?>
                                        <div class="attachment-item"
                                             onclick="openLightbox('<?= e(addslashes($full_url)) ?>', '<?= e(addslashes($filename)) ?>', '<?= $is_image ? 'image' : ($is_video ? 'video' : 'file') ?>')"
                                             title="Click to view: <?= e($filename) ?>">
                                            <?php if ($is_image): ?>
                                                <img src="<?= e($full_url) ?>" alt="Image: <?= e($filename) ?>" loading="lazy">
                                            <?php elseif ($is_video): ?>
                                                <video muted playsinline>
                                                    <source src="<?= e($full_url) ?>" type="video/<?= e($file_type) ?>">
                                                    <div class="attachment-placeholder">🎥</div>
                                                </video>
                                            <?php else: ?>
                                                <div class="attachment-placeholder"><?= strtoupper(e($file_type)) ?></div>
                                            <?php endif; ?>
                                            <div class="attachment-filename"><?= e($filename) ?></div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="card">
                            <h3 class="section-title">🕒 Status & Notes Timeline</h3>
                            <div class="timeline">
                                <?php if ($history): foreach ($history as $h): ?>
                                    <div class="timeline-item">
                                        <span class="timeline-dot"></span>
                                        <div class="timeline-header">
                                            <div class="timeline-status"><?= e($h['status']) ?></div>
                                            <div class="text-xs muted"><?= e($h['created_at'] ?? '') ?></div>
                                        </div>
                                        <?php if ($h['note']): ?>
                                            <div class="timeline-note"><?= nl2br(e($h['note'])) ?></div>
                                        <?php endif; ?>
                                        <div class="timeline-meta">
                                            <span>by <?= e($h['user_name'] ?: 'System') ?></span>
                                        </div>
                                    </div>
                                <?php endforeach; else: ?>
                                    <div class="timeline-empty">No timeline entries yet.</div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </article>
            <?php endforeach; ?>
            <?php if (empty($pendingQuoteRows)): ?>
                <p class="muted text-center py-4">No quotes are currently pending approval.</p>
            <?php endif; ?>
        </div>
    </section>

    <!-- Approved Quotes -->
    <section id="approved-quotes" class="tab-content" role="tabpanel" aria-labelledby="tab-approved-quotes">
        <input type="text" class="search-input" placeholder="🔎 Search (ticket, device, customer)" data-target="list-approved-quotes" aria-label="Search Approved Quotes">
        <div id="list-approved-quotes">
            <?php foreach ($approvedQuoteRows as $r):
                $plist = parts_for($r['id']);
                $history = history_for($r['id']);
                $problemText = $r['problem'] ?? ($r['problem_reported'] ?? ($r['issue_description'] ?? ($r['notes'] ?? '')));
                $problemList = $problemText ? array_map('trim', explode(';', $problemText)) : [];
                $problemList = array_filter($problemList, fn($item) => !empty($item));
            ?>
            <article class="card searchable"
                data-ticket="<?= strtolower(e($r['ticket_code'])) ?>"
                data-device="<?= strtolower(e($r['device_type'])) ?>"
                data-brand="<?= strtolower(e($r['brand'] ?? '')) ?>"
                data-model="<?= strtolower(e($r['model'] ?? '')) ?>"
                data-customer="<?= strtolower(e($r['cust_name'])) ?>">
                <div class="card-header">
                    <div>
                        <div class="flex items-center gap-2 flex-wrap">
                            <h2><?= e($r['ticket_code']) ?></h2>
                            <span class="chip status-inrepair"><?= e($r['status']) ?></span>
                            <span class="chip status-approved">Quote #<?= e($r['invoice_id']) ?> — <?= e($r['quote_status']) ?></span>
                        </div>
                        <div class="text-sm muted mt-1">
                            <?= e($r['device_type']) ?><?= $r['brand'] ? ' · <span class="font-medium">' . e($r['brand']) . '</span>' : '' ?><?= $r['model'] ? ' · <span class="font-medium">' . e($r['model']) . '</span>' : '' ?>
                        </div>
                        <div class="text-sm muted mt-1">
                            Customer: <span class="font-medium"><?= e($r['cust_name']) ?></span> • <?= e($r['cust_email']) ?><?= $r['cust_phone'] ? ' • ' . e($r['cust_phone']) : '' ?>
                        </div>
                        <div class="text-sm muted mt-1">
                            Approved on: <?= e($r['quote_approved_at'] ? date('M j, Y g:i A', strtotime($r['quote_approved_at'])) : 'N/A') ?>
                        </div>
                    </div>
                </div>

                <div class="grid-2">
                    <div>
                        <div class="highlight-box">
                            <h3 class="section-title">⚠️ Problem Reported</h3>
                            <?php if ($problemText): ?>
                                <div class="text-sm"><?= implode(', ', array_slice(array_map('e', $problemList), 0, 3)) ?><?= count($problemList) > 3 ? '...' : '' ?></div>
                            <?php else: ?>
                                <div class="text-sm muted">No problem description provided.</div>
                            <?php endif; ?>
                        </div>

                        <div class="card">
                            <h3 class="section-title">🧰 Approved Parts</h3>
                            <div class="overflow-x-auto">
                                <table class="parts-table">
                                    <thead>
                                        <tr>
                                            <th>Item</th>
                                            <th>Qty</th>
                                            <th>Unit</th>
                                            <th>Remarks</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($plist as $p): ?>
                                            <tr>
                                                <td><?= e($p['item']) ?></td>
                                                <td><?= e($p['qty']) ?></td>
                                                <td class="muted"><?= e($p['unit']) ?></td>
                                                <td class="muted"><?= e($p['remarks']) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                        <?php if (!$plist): ?>
                                            <tr><td colspan="4" class="text-center muted py-2">No parts listed.</td></tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <div>
                        <div class="card mb-4">
                            <h3 class="section-title">📎 Attachments</h3>
                            <?php if (empty($r['attachments'])): ?>
                                <span class="no-attachments">No files attached.</span>
                            <?php else: ?>
                                <div class="attachments-grid">
                                    <?php foreach (array_slice($r['attachments'], 0, 4) as $attachment): ?>
                                        <?php
                                            $file_path = $attachment['file_path'];
                                            $file_type = strtolower($attachment['file_type']);
                                            $full_url = get_file_url($file_path);
                                            $filename = basename($file_path);
                                            $is_image = is_image_type($file_type);
                                            $is_video = is_video_type($file_type);
                                        ?>
                                        <div class="attachment-item"
                                             onclick="openLightbox('<?= e(addslashes($full_url)) ?>', '<?= e(addslashes($filename)) ?>', '<?= $is_image ? 'image' : ($is_video ? 'video' : 'file') ?>')"
                                             title="Click to view: <?= e($filename) ?>">
                                            <?php if ($is_image): ?>
                                                <img src="<?= e($full_url) ?>" alt="Image: <?= e($filename) ?>" loading="lazy">
                                            <?php elseif ($is_video): ?>
                                                <video muted playsinline>
                                                    <source src="<?= e($full_url) ?>" type="video/<?= e($file_type) ?>">
                                                    <div class="attachment-placeholder">🎥</div>
                                                </video>
                                            <?php else: ?>
                                                <div class="attachment-placeholder"><?= strtoupper(e($file_type)) ?></div>
                                            <?php endif; ?>
                                            <div class="attachment-filename"><?= e($filename) ?></div>
                                        </div>
                                    <?php endforeach; ?>
                                    <?php if (count($r['attachments']) > 4): ?>
                                        <div class="attachment-item" style="display: flex; align-items: center; justify-content: center; background: var(--field-border); color: var(--muted); font-size: 0.8rem;">
                                            +<?= count($r['attachments']) - 4 ?> more
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="card">
                            <h3 class="section-title">🕒 Status & Notes Timeline</h3>
                            <div class="timeline">
                                <?php if ($history): foreach ($history as $h): ?>
                                    <div class="timeline-item">
                                        <span class="timeline-dot"></span>
                                        <div class="timeline-header">
                                            <div class="timeline-status"><?= e($h['status']) ?></div>
                                            <div class="text-xs muted"><?= e($h['created_at'] ?? '') ?></div>
                                        </div>
                                        <?php if ($h['note']): ?>
                                            <div class="timeline-note"><?= nl2br(e($h['note'])) ?></div>
                                        <?php endif; ?>
                                        <div class="timeline-meta">
                                            <span>by <?= e($h['user_name'] ?: 'System') ?></span>
                                        </div>
                                    </div>
                                <?php endforeach; else: ?>
                                    <div class="timeline-empty">No timeline entries yet.</div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </article>
            <?php endforeach; ?>
            <?php if (empty($approvedQuoteRows)): ?>
                <p class="muted text-center py-4">No quotes have been approved yet.</p>
            <?php endif; ?>
        </div>
    </section>

    <!-- Ready for Billing / Billed -->
    <section id="billed-requests" class="tab-content" role="tabpanel" aria-labelledby="tab-billed-requests">
        <input type="text" class="search-input" placeholder="🔎 Search (ticket, device, customer)" data-target="list-billed-requests" aria-label="Search Billed Requests">
        <div id="list-billed-requests">
            <?php foreach ($billedRows as $r):
                $plist = parts_for($r['id']);
                $history = history_for($r['id']);
                $problemText = $r['problem'] ?? ($r['problem_reported'] ?? ($r['issue_description'] ?? ($r['notes'] ?? '')));
                $problemList = $problemText ? array_map('trim', explode(';', $problemText)) : [];
                $problemList = array_filter($problemList, fn($item) => !empty($item));
            ?>
            <article class="card searchable"
                data-ticket="<?= strtolower(e($r['ticket_code'])) ?>"
                data-device="<?= strtolower(e($r['device_type'])) ?>"
                data-brand="<?= strtolower(e($r['brand'] ?? '')) ?>"
                data-model="<?= strtolower(e($r['model'] ?? '')) ?>"
                data-customer="<?= strtolower(e($r['cust_name'])) ?>">
                <div class="card-header">
                    <div>
                        <div class="flex items-center gap-2 flex-wrap">
                            <h2><?= e($r['ticket_code']) ?></h2>
                            <span class="chip status-billed"><?= e($r['status']) ?></span>
                            <span class="chip status-<?= strtolower($r['invoice_status']) ?>">Invoice #<?= e($r['invoice_id']) ?> — <?= e($r['invoice_status']) ?></span>
                        </div>
                        <div class="text-sm muted mt-1">
                            <?= e($r['device_type']) ?><?= $r['brand'] ? ' · <span class="font-medium">' . e($r['brand']) . '</span>' : '' ?><?= $r['model'] ? ' · <span class="font-medium">' . e($r['model']) . '</span>' : '' ?>
                        </div>
                        <div class="text-sm muted mt-1">
                            Customer: <span class="font-medium"><?= e($r['cust_name']) ?></span> • <?= e($r['cust_email']) ?><?= $r['cust_phone'] ? ' • ' . e($r['cust_phone']) : '' ?>
                        </div>
                        <div class="text-sm muted mt-1">
                            Billed on: <?= e($r['billed_at'] ? date('M j, Y g:i A', strtotime($r['billed_at'])) : 'N/A') ?>
                        </div>
                    </div>
                    <?php if ($r['invoice_status'] !== 'Finalized'): ?>
                        <form method="post" style="display:inline;">
                            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="rid" value="<?= e($r['id']) ?>">
                            <button class="btn success" name="act" value="generate_invoice" onclick="return confirm('Generate final invoice?');">🖨️ Generate Invoice</button>
                        </form>
                    <?php endif; ?>
                </div>

                <div class="grid-2">
                    <div>
                        <div class="highlight-box">
                            <h3 class="section-title">⚠️ Problem Reported</h3>
                            <?php if ($problemText): ?>
                                <div class="text-sm"><?= implode(', ', array_slice(array_map('e', $problemList), 0, 3)) ?><?= count($problemList) > 3 ? '...' : '' ?></div>
                            <?php else: ?>
                                <div class="text-sm muted">No problem description provided.</div>
                            <?php endif; ?>
                        </div>

                        <div class="card">
                            <h3 class="section-title">🧰 Parts Used</h3>
                            <div class="overflow-x-auto">
                                <table class="parts-table">
                                    <thead>
                                        <tr>
                                            <th>Item</th>
                                            <th>Qty</th>
                                            <th>Unit</th>
                                            <th>Remarks</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($plist as $p): ?>
                                            <tr>
                                                <td><?= e($p['item']) ?></td>
                                                <td><?= e($p['qty']) ?></td>
                                                <td class="muted"><?= e($p['unit']) ?></td>
                                                <td class="muted"><?= e($p['remarks']) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                        <?php if (!$plist): ?>
                                            <tr><td colspan="4" class="text-center muted py-2">No parts listed.</td></tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                            <?php if ($r['invoice_status'] === 'Finalized'): ?>
                                <div class="mt-4">
                                    <h4>Invoice Details</h4>
                                    <div class="text-sm">
                                        <p>Subtotal: $<?= number_format($r['subtotal'], 2) ?></p>
                                        <p>Tax (<?= number_format($r['tax_rate'] * 100, 2) ?>%): $<?= number_format($r['tax_amount'], 2) ?></p>
                                        <p><strong>Total: $<?= number_format($r['total'], 2) ?></strong></p>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div>
                        <div class="card mb-4">
                            <h3 class="section-title">📎 Attachments</h3>
                            <?php if (empty($r['attachments'])): ?>
                                <span class="no-attachments">No files attached.</span>
                            <?php else: ?>
                                <div class="attachments-grid">
                                    <?php foreach (array_slice($r['attachments'], 0, 4) as $attachment): ?>
                                        <?php
                                            $file_path = $attachment['file_path'];
                                            $file_type = strtolower($attachment['file_type']);
                                            $full_url = get_file_url($file_path);
                                            $filename = basename($file_path);
                                            $is_image = is_image_type($file_type);
                                            $is_video = is_video_type($file_type);
                                        ?>
                                        <div class="attachment-item"
                                             onclick="openLightbox('<?= e(addslashes($full_url)) ?>', '<?= e(addslashes($filename)) ?>', '<?= $is_image ? 'image' : ($is_video ? 'video' : 'file') ?>')"
                                             title="Click to view: <?= e($filename) ?>">
                                            <?php if ($is_image): ?>
                                                <img src="<?= e($full_url) ?>" alt="Image: <?= e($filename) ?>" loading="lazy">
                                            <?php elseif ($is_video): ?>
                                                <video muted playsinline>
                                                    <source src="<?= e($full_url) ?>" type="video/<?= e($file_type) ?>">
                                                    <div class="attachment-placeholder">🎥</div>
                                                </video>
                                            <?php else: ?>
                                                <div class="attachment-placeholder"><?= strtoupper(e($file_type)) ?></div>
                                            <?php endif; ?>
                                            <div class="attachment-filename"><?= e($filename) ?></div>
                                        </div>
                                    <?php endforeach; ?>
                                    <?php if (count($r['attachments']) > 4): ?>
                                        <div class="attachment-item" style="display: flex; align-items: center; justify-content: center; background: var(--field-border); color: var(--muted); font-size: 0.8rem;">
                                            +<?= count($r['attachments']) - 4 ?> more
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="card">
                            <h3 class="section-title">🕒 Status & Notes Timeline</h3>
                            <div class="timeline">
                                <?php if ($history): foreach ($history as $h): ?>
                                    <div class="timeline-item">
                                        <span class="timeline-dot"></span>
                                        <div class="timeline-header">
                                            <div class="timeline-status"><?= e($h['status']) ?></div>
                                            <div class="text-xs muted"><?= e($h['created_at'] ?? '') ?></div>
                                        </div>
                                        <?php if ($h['note']): ?>
                                            <div class="timeline-note"><?= nl2br(e($h['note'])) ?></div>
                                        <?php endif; ?>
                                        <div class="timeline-meta">
                                            <span>by <?= e($h['user_name'] ?: 'System') ?></span>
                                        </div>
                                    </div>
                                <?php endforeach; else: ?>
                                    <div class="timeline-empty">No timeline entries yet.</div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </article>
            <?php endforeach; ?>
            <?php if (empty($billedRows)): ?>
                <p class="muted text-center py-4">No requests are ready for billing or billed.</p>
            <?php endif; ?>
        </div>
    </section>
</main>

<!-- Lightbox Modal -->
<div id="lightbox" class="lightbox">
    <button class="lightbox-close" onclick="closeLightbox()">×</button>
    <div id="lightbox-content" class="lightbox-content"></div>
    <div id="lightbox-filename" class="lightbox-filename"></div>
</div>

<script>
    // Lightbox Functionality
    const lightbox = document.getElementById('lightbox');
    const lightboxContent = document.getElementById('lightbox-content');
    const lightboxFilename = document.getElementById('lightbox-filename');

    function openLightbox(url, filename, type) {
        lightboxContent.innerHTML = '';
        lightboxFilename.textContent = filename;

        if (type === 'image') {
            const img = document.createElement('img');
            img.src = url;
            img.alt = filename;
            img.style.borderRadius = '12px';
            lightboxContent.appendChild(img);
        } else if (type === 'video') {
            const video = document.createElement('video');
            video.src = url;
            video.controls = true;
            video.autoplay = true;
            video.muted = false;
            video.style.borderRadius = '12px';
            lightboxContent.appendChild(video);
        } else {
            const link = document.createElement('a');
            link.href = url;
            link.target = "_blank";
            link.textContent = `📄 View or download: ${filename}`;
            link.style.color = 'white';
            link.style.textDecoration = 'underline';
            lightboxContent.appendChild(link);
        }

        lightbox.classList.add('active');
        document.body.style.overflow = 'hidden';
    }

    function closeLightbox() {
        lightbox.classList.remove('active');
        document.body.style.overflow = '';
        const video = lightboxContent.querySelector('video');
        if (video) video.pause();
    }

    lightbox.addEventListener('click', (e) => {
        if (e.target === lightbox) closeLightbox();
    });

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && lightbox.classList.contains('active')) {
            closeLightbox();
        }
    });

    // Toggle Problem List Dropdown
    function toggleProblemList(triggerElement, requestId) {
        const dropdown = document.getElementById('problem-list-' + requestId);
        const icon = document.getElementById('dropdown-icon-' + requestId);
        if (dropdown && icon) {
            dropdown.classList.toggle('hidden');
            icon.textContent = dropdown.classList.contains('hidden') ? '▼' : '▲';
        }
    }

    // Tab Switching
    document.querySelectorAll('.tab-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            document.querySelectorAll('.tab-btn').forEach(b => {
                b.classList.remove('active');
                b.setAttribute('aria-selected', 'false');
            });
            btn.classList.add('active');
            btn.setAttribute('aria-selected', 'true');

            const id = btn.dataset.tab;
            document.querySelectorAll('.tab-content').forEach(sec => sec.classList.remove('active'));
            document.getElementById(id).classList.add('active');
        });
    });

    // Live Search
    function debounce(func, delay) {
        let timer;
        return function () {
            const context = this, args = arguments;
            clearTimeout(timer);
            timer = setTimeout(() => func.apply(context, args), delay);
        };
    }
    const applySearch = debounce(() => {
        document.querySelectorAll('.search-input').forEach(input => {
            const term = input.value.toLowerCase().trim();
            const listId = input.dataset.target;
            const list = document.getElementById(listId);
            if (!list) return;
            list.querySelectorAll('.searchable').forEach(card => {
                const searchableText = [
                    card.dataset.ticket,
                    card.dataset.device,
                    card.dataset.brand,
                    card.dataset.model,
                    card.dataset.customer
                ].join(' ').toLowerCase();
                card.style.display = searchableText.includes(term) ? '' : 'none';
            });
        });
    }, 150);
    document.querySelectorAll('.search-input').forEach(input => {
        input.addEventListener('input', applySearch);
    });
</script>
</body>
</html>