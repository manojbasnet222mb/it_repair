<?php
/**
 * Staff Repair Desk — NexusFix (Professional Edition)
 * Inspired by top-tier UX/UI design principles.
 * Features: Tabbed views, responsive cards, inline editing, parts listing, quote management, timeline.
 * Enhanced with: Consistent dark/light mode, improved visual hierarchy, streamlined actions,
 *                Attachment previews, Expandable problem list, Collapsible sections.
 * Modified for 3-tab workflow:
 * - Tab 1: Needs Quote (In Repair, No/Pending/Rejected Quote)
 * - Tab 2: Quote Pending Approval (Managed by Billing Desk)
 * - Tab 3: Approved & Repairing (Customer Approved Quote)
 */
declare(strict_types=1);
require_once __DIR__.'/../../includes/bootstrap.php';
require_role('staff','admin');

$note = null;
$errors = [];

// --- Attachment Helpers (Copied from customer/requests.php) ---
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
    // Assumes base URL structure like http://localhost/it_repair/
    // and file paths stored like uploads/requests/123/filename.ext
    $base = rtrim(dirname(base_url()), '/'); // Gets path part before /public, e.g., /it_repair
    return $base . '/' . ltrim($file_path, '/'); // Combine base with stored path
}

// --- End Attachment Helpers ---

// --- Existing Helpers ---------------------------------------------------------------
function get_invoice($rid){
  $s = db()->prepare("SELECT * FROM invoices WHERE request_id=?");
  $s->execute([$rid]);
  return $s->fetch();
}

function ensure_invoice($rid, $userId){
  $inv = get_invoice($rid);
  if(!$inv){
    $s = db()->prepare("INSERT INTO invoices (request_id,created_by,status,quote_status) VALUES (?,?, 'Draft','Pending')");
    $s->execute([$rid,$userId]);
    return get_invoice($rid);
  }
  return $inv;
}

function add_history($rid,$status,$note,$userId){
  $s = db()->prepare("INSERT INTO request_status_history (request_id,status,note,changed_by) VALUES (?,?,?,?)");
  return $s->execute([$rid,$status,$note,$userId]);
}

function parts_for($rid){
  $s = db()->prepare("SELECT * FROM request_parts WHERE request_id=? ORDER BY id DESC");
  $s->execute([$rid]);
  return $s->fetchAll();
}

function history_for($rid){
  $s = db()->prepare("SELECT h.*, u.name as user_name
                      FROM request_status_history h
                      LEFT JOIN users u ON u.id = h.changed_by
                      WHERE h.request_id=?
                      ORDER BY h.id DESC");
  $s->execute([$rid]);
  return $s->fetchAll();
}

// --- POST actions ----------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_verify($_POST['csrf'] ?? '')) {
    $errors['csrf'] = 'Invalid session token. Please refresh the page.';
  } else {
    $act = $_POST['act'] ?? '';
    $rid = (int)($_POST['rid'] ?? 0);

    // Load request once
    $reqStmt = db()->prepare("SELECT * FROM repair_requests WHERE id=?");
    $reqStmt->execute([$rid]);
    $req = $reqStmt->fetch();
    if(!$req){
      $errors['req'] = 'Request not found.';
    }

    if(!$errors){
      $userId = $_SESSION['user']['id'];
      $isInRepair = ($req['status'] === 'In Repair');

      // Add Part
      if ($act === 'addpart') {
        if(!$isInRepair){
          $errors['status'] = 'Cannot modify parts unless the request is In Repair.';
        } else {
          $item = trim($_POST['item'] ?? '');
          $unit = trim($_POST['unit'] ?? 'pcs');
          $qty  = (float)($_POST['qty'] ?? 1);
          $remarks = trim($_POST['remarks'] ?? '');
          if (!$item) $errors['item'] = 'Item name is required.';
          if ($qty <= 0) $errors['qty'] = 'Quantity must be greater than 0.';
          if (!$errors) {
            $s = db()->prepare("INSERT INTO request_parts (request_id,item,unit,qty,remarks,added_by) VALUES (?,?,?,?,?,?)");
            $s->execute([$rid,$item,$unit,$qty,$remarks,$userId]);
            $note = 'Part added successfully.';
          }
        }
      }

      // Remove Part
      if (!$errors && $act === 'delpart') {
        if(!$isInRepair){
          $errors['status'] = 'Cannot remove parts unless the request is In Repair.';
        } else {
          $pid = (int)($_POST['pid'] ?? 0);
          if ($pid) {
            $s = db()->prepare("DELETE FROM request_parts WHERE id=? AND request_id=?");
            $s->execute([$pid,$rid]);
            $note = 'Part removed successfully.';
          }
        }
      }

      // Update device details (quick edit)
      if (!$errors && $act === 'update_device') {
        $brand = trim($_POST['brand'] ?? '');
        $model = trim($_POST['model'] ?? '');
        $serial= trim($_POST['serial_no'] ?? '');
        if(!$brand || !$model || !$serial){
          $errors['device'] = 'Brand, Model, and Serial/IMEI are all required.';
        } else {
          $s = db()->prepare("UPDATE repair_requests SET brand=?, model=?, serial_no=? WHERE id=?");
          $s->execute([$brand,$model,$serial,$rid]);
          $note = 'Device details updated.';
        }
      }

      // Add internal note to timeline
      if (!$errors && $act === 'add_note') {
        $n = trim($_POST['note_text'] ?? '');
        if(!$n){
          $errors['note_text'] = 'Note content cannot be empty.';
        } else {
          $cur = $req['status'];
          add_history($rid,$cur,'Technician note: '.$n,$userId);
          $note = 'Note added to the timeline.';
        }
      }

      // --- PHASE 1, STEP 1: Generate Quote Action ---
      if (!$errors && $act === 'generate_quote') {
        if(!$isInRepair){
          $errors['status'] = 'Quotes can only be generated while the request is In Repair.';
        } else {
          $pdo = db();
          $pdo->beginTransaction();
          try{
            $invoice = ensure_invoice($rid,$userId);
            $s = $pdo->prepare("UPDATE invoices SET status='Draft', quote_status='Pending' WHERE id=?");
            $s->execute([$invoice['id']]);
            add_history($rid,'In Repair','Quote generated (Pending approval)',$userId);
            $pdo->commit();
            $note = 'Quote generated and is now pending approval by Billing Desk.';
          } catch(Exception $e){
            $pdo->rollBack();
            $errors['quote'] = 'Failed to generate the quote. Please try again.';
            error_log("Quote Generation Error (RID: $rid): " . $e->getMessage());
          }
        }
      }


      // Send to Billing (Assuming this happens after quote is approved and repair is done)
      // This action might need refinement based on your exact workflow
      if (!$errors && $act === 'complete') {
        $invoice = get_invoice($rid);
        if ($invoice && $invoice['quote_status'] === 'Approved' && $isInRepair) {
          $pdo = db();
          $pdo->beginTransaction();
          try{
            $pdo->prepare("UPDATE repair_requests SET status='Billed' WHERE id=?")->execute([$rid]);
            add_history($rid,'Billed','Forwarded to Billing',$userId);
            $pdo->commit();
            $note = 'Request forwarded to Billing successfully.';
          } catch(Exception $e){
            $pdo->rollBack();
            $errors['complete'] = 'Failed to forward to Billing. Please try again.';
            error_log("Billing Forward Error (RID: $rid): " . $e->getMessage());
          }
        } else {
          $errors['complete'] = 'Cannot complete: Quote must be approved and request must be In Repair.';
        }
      }

      // Mark Delivered
      if (!$errors && $act === 'mark_delivered') {
        if($req['status'] !== 'Billed'){
          $errors['deliver'] = 'Only billed requests can be marked as delivered.';
        } else {
          $pdo = db();
          $pdo->beginTransaction();
          try{
            $pdo->prepare("UPDATE repair_requests SET status='Delivered' WHERE id=?")->execute([$rid]);
            add_history($rid,'Delivered','Device delivered to customer',$userId);
            $pdo->commit();
            $note = 'Request marked as Delivered.';
          } catch(Exception $e){
            $pdo->rollBack();
            $errors['deliver'] = 'Failed to mark as delivered. Please try again.';
            error_log("Delivery Mark Error (RID: $rid): " . $e->getMessage());
          }
        }
      }
    }
  }
}

// --- Queries for view (with attachments) -------------------------------------------------------
// Fetch requests grouped by our new workflow states
$needsQuoteRows = db()->query("
  SELECT rr.*, u.name cust_name, u.email cust_email, u.phone cust_phone,
         i.id as invoice_id, i.quote_status
  FROM repair_requests rr
  JOIN users u ON u.id=rr.customer_id
  LEFT JOIN invoices i ON i.request_id = rr.id
  WHERE rr.status = 'In Repair'
    AND (i.quote_status IS NULL OR i.quote_status IN ('Rejected'))
  ORDER BY rr.id DESC
")->fetchAll();

// --- Tab 2: Quote Pending Approval ---
// This tab now shows requests where the invoice exists and is 'Pending'.
// The billing desk manages the approval/rejection process.
// The repair desk sees status updates from the billing desk.
$pendingApprovalRows = db()->query("
  SELECT rr.*, u.name cust_name, u.email cust_email, u.phone cust_phone,
         i.id as invoice_id, i.quote_status
  FROM repair_requests rr
  JOIN users u ON u.id=rr.customer_id
  JOIN invoices i ON i.request_id = rr.id
  WHERE i.quote_status = 'Pending' AND rr.status = 'In Repair'
  ORDER BY rr.id DESC
")->fetchAll();

// --- Tab 3: Approved & Repairing ---
// This tab shows requests where the customer has approved the quote.
// The repair desk does the work and then sends it to billing.
$approvedRepairingRows = db()->query("
  SELECT rr.*, u.name cust_name, u.email cust_email, u.phone cust_phone,
         i.id as invoice_id, i.quote_status
  FROM repair_requests rr
  JOIN users u ON u.id=rr.customer_id
  JOIN invoices i ON i.request_id = rr.id
  WHERE i.quote_status = 'Approved' AND rr.status = 'In Repair'
  ORDER BY rr.id DESC
")->fetchAll();

// Combine all rows for searching/filtering if needed later, or keep separate
// For now, we'll process them separately for each tab.
// Add attachments to each row
function add_attachments(&$rows) {
    foreach ($rows as &$row) {
        $row['attachments'] = get_request_attachments((int)$row['id']);
    }
    unset($row); // Unset reference variable
}
add_attachments($needsQuoteRows);
add_attachments($pendingApprovalRows);
add_attachments($approvedRepairingRows);

?>
<!doctype html>
<html lang="en" data-theme="dark">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Repair Desk — NexusFix</title>
<link rel="stylesheet" href="../../assets/css/styles.css">
<style>
/* --- Theme Variables --- */
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

/* --- Base Styles --- */
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

/* --- Layout & Cards --- */
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

/* --- Tabs --- */
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

/* --- Status Chips --- */
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
.status-delivered   { background: rgba(16, 185, 129, 0.15); color: var(--success); border: 1px solid rgba(16, 185, 129, 0.3); }
.status-pending     { background: rgba(163, 163, 163, 0.15); color: var(--muted); border: 1px solid rgba(163, 163, 163, 0.3); }
.status-approved    { background: rgba(16, 185, 129, 0.15); color: var(--success); border: 1px solid rgba(16, 185, 129, 0.3); }
.status-rejected    { background: rgba(248, 113, 113, 0.15); color: var(--danger); border: 1px solid rgba(248, 113, 113, 0.3); }

/* --- Buttons --- */
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
.btn:focus-visible {
  outline: 3px solid rgba(96, 165, 250, 0.45);
  outline-offset: 2px;
}
.btn:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}
.btn.primary {
  background: var(--primary);
  color: white;
}
.btn.primary:hover {
  background: #4f9cf9;
  transform: translateY(-1px);
}
.btn.outline {
  background: transparent;
  color: var(--muted);
  border: 1px solid var(--field-border);
}
.btn.outline:hover {
  background: rgba(255,255,255,.06);
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
.btn.subtle {
  background: rgba(255,255,255,.06);
  color: var(--text);
  border: 1px solid transparent;
}
.btn.subtle:hover {
  background: rgba(255,255,255,.1);
}

/* --- Forms & Inputs --- */
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
input:focus, select:focus, textarea:focus {
  outline: none;
  border-color: var(--primary);
  box-shadow: 0 0 0 3px rgba(96, 165, 250, 0.2);
}
label {
  display: block;
  font-weight: 500;
  font-size: 0.9rem;
  margin-bottom: 0.4rem;
  color: var(--text);
}
.form-row {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
  gap: 1rem;
  margin-bottom: 1rem;
}
.form-group {
    margin-bottom: 1rem;
}
.form-inline {
    display: flex;
    gap: 0.5rem;
    align-items: flex-end;
}
.form-inline .btn {
    flex-shrink: 0;
}

/* --- Messages --- */
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

/* --- Search --- */
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

/* --- Grid Layout --- */
.grid-2 { display: grid; gap: 1.5rem; }
.grid-2 { grid-template-columns: 1fr; }
@media (min-width: 992px) {
  .grid-2 { grid-template-columns: 2fr 1fr; }
}

/* --- Timeline --- */
.timeline {
  position: relative;
  padding-left: 1.5rem;
  margin-top: 1rem;
}
.timeline::before {
  content: '';
  position: absolute;
  left: 10px; /* 1rem - 6px (dot width/2) */
  top: 0;
  bottom: 0;
  width: 2px;
  background: var(--field-border);
}
.timeline-item {
  position: relative;
  margin-bottom: 1.25rem;
}
.timeline-item:last-child {
    margin-bottom: 0;
}
.timeline-dot {
  position: absolute;
  left: -1.6rem; /* Adjust based on padding and dot size */
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

/* --- Tables --- */
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
.parts-table .actions-cell {
  text-align: right;
}

/* --- Sections --- */
.section-title {
    margin-bottom: 0.75rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}
.highlight-box {
    background: rgba(251, 191, 36, 0.1); /* Amber 100 equivalent */
    border: 1px solid rgba(251, 191, 36, 0.3); /* Amber 200 equivalent */
    border-radius: 12px;
    padding: 1rem;
    margin-bottom: 1rem;
}
.highlight-box .section-title {
    color: var(--warning); /* Amber 500 equivalent */
    margin-top: 0;
}
.problem-list {
    list-style-type: none;
    padding-left: 0;
    margin: 0.5rem 0 0;
    max-height: 200px; /* Optional: Add scroll if list is long */
    overflow-y: auto;   /* Optional: Add scroll if list is long */
}
.problem-list li {
    padding: 0.25rem 0;
    border-bottom: 1px dotted var(--field-border);
}
.problem-list li:last-child {
    border-bottom: none;
}

/* --- Dropdown for Problem List (Custom) --- */
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
    max-height: 200px; /* Optional: Add scroll */
    overflow-y: auto;   /* Optional: Add scroll */
    z-index: 10; /* Ensure it appears above other content */
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
/* --- End Dropdown for Problem List --- */

/* --- Attachments (from customer/requests.php) --- */
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
/* --- Lightbox Modal (from customer/requests.php) --- */
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
.lightbox-video {
  background: #000;
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

/* --- Utils --- */
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
.mb-6 { margin-bottom: 1.5rem; } /* Added for spacing */

@media (prefers-reduced-motion: reduce) {
  *,
  *::before,
  *::after {
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
    <h1 id="page-title">Repair Desk</h1>
    <?php if($note): ?>
        <div class="alert success" role="alert">
            <strong>Success:</strong> <?= e($note) ?>
        </div>
    <?php endif; ?>
    <?php if($errors): ?>
        <div class="alert error" role="alert">
            <strong>Please fix the following:</strong>
            <ul>
                <?php foreach($errors as $key => $m): ?>
                    <?php if ($key !== 'csrf'): // Don't show CSRF error inline ?>
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
    <button class="tab-btn active" data-tab="needs-quote" role="tab" aria-selected="true">📋 Needs Quote</button>
    <button class="tab-btn" data-tab="pending-approval" role="tab" aria-selected="false">⏳ Quote Pending Approval</button>
    <button class="tab-btn" data-tab="approved-repairing" role="tab" aria-selected="false">🛠️ Approved & Repairing</button>
  </div>

  <!-- Needs Quote -->
  <section id="needs-quote" class="tab-content active" role="tabpanel" aria-labelledby="tab-needs-quote">
    <input type="text" class="search-input" placeholder="🔎 Search (ticket, device, customer, parts, notes)" data-target="list-needs-quote" aria-label="Search Needs Quote requests">
    <div id="list-needs-quote">
      <?php foreach($needsQuoteRows as $r):
        $plist   = parts_for($r['id']);
        // $invoice might be null or have quote_status 'Rejected'
        $invoice = get_invoice($r['id']);
        $history = history_for($r['id']);
        // Fallback chain for problem description
        $problemText = $r['problem'] ?? ($r['problem_reported'] ?? ($r['issue_description'] ?? ($r['notes'] ?? '')));
        // Parse problem list (assuming '; ' separator)
        $problemList = $problemText ? array_map('trim', explode(';', $problemText)) : [];
        // Filter out empty items that might result from trailing ';'
        $problemList = array_filter($problemList, fn($item) => !empty($item));
      ?>
      <article class="card searchable" data-rid="<?= e($r['id']) ?>"
        data-ticket="<?= strtolower(e($r['ticket_code'])) ?>"
        data-device="<?= strtolower(e($r['device_type'])) ?>"
        data-brand="<?= strtolower(e($r['brand'] ?? '')) ?>"
        data-model="<?= strtolower(e($r['model'] ?? '')) ?>"
        data-customer="<?= strtolower(e($r['cust_name'])) ?>">
        <!-- Header -->
        <div class="card-header">
          <div>
            <div class="flex items-center gap-2 flex-wrap">
              <h2><?= e($r['ticket_code']) ?></h2>
              <span class="chip status-inrepair"><?= e($r['status']) ?></span>
              <?php if($invoice): ?>
                <?php
                    $quoteStatusClass = 'status-rejected'; // Default for this tab
                    if ($invoice['quote_status'] === 'Rejected') $quoteStatusClass = 'status-rejected';
                    // This condition should ideally not happen here due to query, but good safeguard
                ?>
                <span class="chip <?= $quoteStatusClass ?>">Quote #<?= e($invoice['id']) ?> — <?= e($invoice['quote_status']) ?></span>
              <?php endif; ?>
            </div>
            <div class="text-sm muted mt-1">
              <?= e($r['device_type']) ?><?= $r['brand']?' · <span class="font-medium">'.e($r['brand']).'</span>':'' ?><?= $r['model']?' · <span class="font-medium">'.e($r['model']).'</span>':'' ?>
            </div>
            <div class="text-sm muted mt-1">
              Customer: <span class="font-medium"><?= e($r['cust_name']) ?></span> • <?= e($r['cust_email']) ?><?= $r['cust_phone']?' • '.e($r['cust_phone']):'' ?>
            </div>
          </div>

          <!-- Action Toolbar (Generate Quote is primary here) -->
          <div class="flex flex-wrap gap-2">
            <form method="post">
              <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
              <input type="hidden" name="rid" value="<?= e($r['id']) ?>">
              <button class="btn primary" name="act" value="generate_quote" <?= empty($plist) ? 'disabled title="Add parts first"' : '' ?>>Generate Quote</button>
            </form>
          </div>
        </div>

        <div class="grid-2">
          <!-- Left column -->
          <div>

            <!-- Problem Reported -->
            <div class="highlight-box">
              <h3 class="section-title">⚠️ Problem Reported</h3>
              <?php if ($problemText): ?>
                <?php if (!empty($problemList) && count($problemList) > 1): ?>
                    <!-- Show full text and dropdown list if multiple issues -->
                    <div class="text-sm mb-2"><?= nl2br(e($problemText)) ?></div>
                    <!-- Dropdown Container -->
                    <div class="relative">
                        <!-- Summary acts as the dropdown trigger -->
                        <div class="problem-dropdown-trigger" onclick="toggleProblemList(this, '<?= e($r['id']) ?>')">
                            Show individual issues <span id="dropdown-icon-<?= e($r['id']) ?>">▼</span>
                        </div>
                        <!-- Hidden List (initially) -->
                        <ul id="problem-list-<?= e($r['id']) ?>" class="problem-list-dropdown hidden">
                            <?php foreach ($problemList as $issue): ?>
                                <li><?= e($issue) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php else: ?>
                    <!-- Show full text if single issue or parsing failed -->
                    <div class="text-sm"><?= nl2br(e($problemText)) ?></div>
                <?php endif; ?>
              <?php else: ?>
                <div class="text-sm muted">No problem description provided.</div>
              <?php endif; ?>
            </div>

            <!-- Device Details -->
            <div class="card">
              <div class="card-header">
                <h3 class="section-title">🖥️ Device Details</h3>
                <button class="btn outline toggle-edit" data-target="#devform-<?= e($r['id']) ?>">✎ Edit</button>
              </div>

              <!-- Read-only display -->
              <div class="form-row">
                <div>
                  <label>Brand</label>
                  <div class="text-sm"><?= e($r['brand']) ?: '—' ?></div>
                </div>
                <div>
                  <label>Model</label>
                  <div class="text-sm"><?= e($r['model']) ?: '—' ?></div>
                </div>
                <div>
                  <label>Serial / IMEI</label>
                  <div class="text-sm"><?= e($r['serial_no']) ?: '—' ?></div>
                </div>
                <div>
                  <label>Device Type</label>
                  <div class="text-sm"><?= e($r['device_type']) ?: '—' ?></div>
                </div>
              </div>

              <!-- Edit form (hidden until toggled) -->
              <form method="post" id="devform-<?= e($r['id']) ?>" class="hidden">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="rid" value="<?= e($r['id']) ?>">

                <div class="form-row">
                  <div>
                    <label for="brand-<?= e($r['id']) ?>">Brand *</label>
                    <input id="brand-<?= e($r['id']) ?>" name="brand" value="<?= e($r['brand']) ?>" required>
                  </div>
                  <div>
                    <label for="model-<?= e($r['id']) ?>">Model *</label>
                    <input id="model-<?= e($r['id']) ?>" name="model" value="<?= e($r['model']) ?>" required>
                  </div>
                  <div>
                    <label for="serial-<?= e($r['id']) ?>">Serial / IMEI *</label>
                    <input id="serial-<?= e($r['id']) ?>" name="serial_no" value="<?= e($r['serial_no']) ?>" required>
                  </div>
                  <div>
                    <label>Device Type</label>
                    <input value="<?= e($r['device_type']) ?>" disabled>
                  </div>
                </div>
                <div class="flex gap-2 mt-4">
                  <button class="btn primary" name="act" value="update_device">💾 Save</button>
                  <button type="button" class="btn subtle toggle-edit" data-target="#devform-<?= e($r['id']) ?>">Cancel</button>
                </div>
              </form>
            </div>

            <!-- Parts Used -->
            <div class="card">
              <div class="card-header">
                <h3 class="section-title">🧰 Parts Used</h3>
              </div>

              <div class="overflow-x-auto">
                <table class="parts-table">
                  <thead>
                    <tr>
                      <th>Item</th>
                      <th>Qty</th>
                      <th>Unit</th>
                      <th>Remarks</th>
                      <th class="actions-cell">Action</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach($plist as $p): ?>
                      <tr>
                        <td><?= e($p['item']) ?></td>
                        <td><?= e($p['qty']) ?></td>
                        <td class="muted"><?= e($p['unit']) ?></td>
                        <td class="muted"><?= e($p['remarks']) ?></td>
                        <td class="actions-cell">
                          <form method="post" class="inline">
                            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="rid" value="<?= e($r['id']) ?>">
                            <input type="hidden" name="pid" value="<?= e($p['id']) ?>">
                            <button class="btn outline" name="act" value="delpart" onclick="return confirm('Remove part \'<?= e($p['item']) ?>\'?')">Remove</button>
                          </form>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                    <?php if(!$plist): ?>
                      <tr><td colspan="5" class="text-center muted py-2">No parts added yet.</td></tr>
                    <?php endif; ?>
                  </tbody>
                </table>
              </div>

              <!-- Add part -->
              <form method="post" class="mt-4">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="rid" value="<?= e($r['id']) ?>">

                <div class="form-row">
                  <div>
                    <label for="item-<?= e($r['id']) ?>">Item *</label>
                    <input id="item-<?= e($r['id']) ?>" name="item" placeholder="e.g., SSD 512GB" required>
                  </div>
                  <div>
                    <label for="unit-<?= e($r['id']) ?>">Unit</label>
                    <input id="unit-<?= e($r['id']) ?>" name="unit" placeholder="pcs" value="pcs">
                  </div>
                  <div>
                    <label for="qty-<?= e($r['id']) ?>">Qty *</label>
                    <input id="qty-<?= e($r['id']) ?>" name="qty" type="number" step="0.01" value="1" min="0.01" required>
                  </div>
                  <div>
                    <label for="remarks-<?= e($r['id']) ?>">Remarks</label>
                    <input id="remarks-<?= e($r['id']) ?>" name="remarks" placeholder="Optional notes">
                  </div>
                </div>
                <button class="btn outline" name="act" value="addpart">➕ Add Part</button>
              </form>
            </div>

            <!-- Technician Notes -->
            <div class="card">
              <div class="card-header">
                <h3 class="section-title">📝 Technician Notes</h3>
              </div>
              <form method="post">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="rid" value="<?= e($r['id']) ?>">
                <div class="form-group">
                    <label for="note_text-<?= e($r['id']) ?>" class="sr-only">Add Note</label>
                    <textarea id="note_text-<?= e($r['id']) ?>" class="w-full" name="note_text" placeholder="Diagnostics, work performed, advice..." rows="3" required></textarea>
                </div>
                <button class="btn subtle" name="act" value="add_note">➕ Add Note</button>
              </form>
            </div>
          </div>

          <!-- Right column -->
          <div>
            <!-- Attachments -->
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

            <!-- Timeline -->
            <div class="card">
              <h3 class="section-title">🕒 Status & Notes Timeline</h3>
              <div class="timeline">
                <?php if($history): foreach($history as $h): ?>
                <div class="timeline-item">
                  <span class="timeline-dot"></span>
                  <div class="timeline-header">
                    <div class="timeline-status"><?= e($h['status']) ?></div>
                    <div class="text-xs muted"><?= e($h['created_at'] ?? '') ?></div>
                  </div>
                  <?php if($h['note']): ?>
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
      <?php if(empty($needsQuoteRows)): ?>
        <p class="muted text-center py-4">No requests need a quote at this time.</p>
      <?php endif; ?>
    </div>
  </section>

  <!-- Quote Pending Approval -->
  <section id="pending-approval" class="tab-content" role="tabpanel" aria-labelledby="tab-pending-approval">
    <input type="text" class="search-input" placeholder="🔎 Search..." data-target="list-pending-approval" aria-label="Search Pending Approval requests">
    <div id="list-pending-approval">
      <?php foreach($pendingApprovalRows as $r):
        $plist   = parts_for($r['id']);
        $invoice = get_invoice($r['id']); // Guaranteed to exist and be 'Pending' by query
        $history = history_for($r['id']);
        // Fallback chain for problem description
        $problemText = $r['problem'] ?? ($r['problem_reported'] ?? ($r['issue_description'] ?? ($r['notes'] ?? '')));
        // Parse problem list (assuming '; ' separator)
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
              <?php if($invoice): ?>
                <span class="chip status-pending">Quote #<?= e($invoice['id']) ?> — <?= e($invoice['quote_status']) ?></span>
              <?php endif; ?>
            </div>
            <div class="text-sm muted mt-1">
              <?= e($r['device_type']) ?><?= $r['brand']?' · <span class="font-medium">'.e($r['brand']).'</span>':'' ?><?= $r['model']?' · <span class="font-medium">'.e($r['model']).'</span>':'' ?>
            </div>
            <div class="text-sm muted mt-1">
              Customer: <span class="font-medium"><?= e($r['cust_name']) ?></span> • <?= e($r['cust_email']) ?><?= $r['cust_phone']?' • '.e($r['cust_phone']):'' ?>
            </div>
          </div>
          <!-- No direct approve/reject buttons here -->
          <div class="text-sm muted">
              <!-- Placeholder for status updates from Billing Desk -->
              <!-- You would populate this with actual data from the invoice or history -->
              <!-- Example logic: Find the last relevant history entry or check invoice fields -->
              <?php
                $sentToCustomerDate = null;
                $requoteRequestedDate = null;
                // Simplified example: Check history for specific notes
                foreach($history as $h) {
                    if (strpos($h['note'], 'Quote generated') !== false) {
                        $sentToCustomerDate = $h['created_at'];
                        break; // Assume first generation is the one sent
                    }
                }
                // Another example: Check if invoice has a specific field or status indicating re-quote
                // For now, we'll just simulate it
                // $requoteRequestedDate = $invoice['requote_requested_at'] ?? null;
              ?>
              <?php if ($sentToCustomerDate): ?>
                  <div>Sent to Customer: <?= e(date('M j, Y g:i A', strtotime($sentToCustomerDate))) ?></div>
              <?php endif; ?>
              <?php if ($requoteRequestedDate): ?>
                  <div>Re-quote Requested: <?= e(date('M j, Y g:i A', strtotime($requoteRequestedDate))) ?></div>
              <?php endif; ?>
              <?php if (!$sentToCustomerDate && !$requoteRequestedDate): ?>
                  <div>Awaiting status update from Billing Desk...</div>
              <?php endif; ?>
          </div>
        </div>

        <div class="grid-2">
          <div>
            <!-- Problem Reported Summary -->
            <div class="highlight-box">
              <h3 class="section-title">⚠️ Problem Reported</h3>
              <?php if ($problemText): ?>
                <?php if (!empty($problemList) && count($problemList) > 1): ?>
                     <div class="text-sm"><?= implode(', ', array_slice(array_map('e', $problemList), 0, 3)) ?><?= count($problemList) > 3 ? '...' : '' ?></div>
                <?php else: ?>
                     <div class="text-sm"><?= e(implode(', ', $problemList)) ?></div>
                <?php endif; ?>
              <?php else: ?>
                <div class="text-sm muted">No problem description provided.</div>
              <?php endif; ?>
            </div>

            <!-- Parts List Summary -->
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
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach($plist as $p): ?>
                      <tr>
                        <td><?= e($p['item']) ?></td>
                        <td><?= e($p['qty']) ?></td>
                        <td class="muted"><?= e($p['unit']) ?></td>
                        <td class="muted"><?= e($p['remarks']) ?></td>
                      </tr>
                    <?php endforeach; ?>
                    <?php if(!$plist): ?>
                      <tr><td colspan="4" class="text-center muted py-2">No parts listed.</td></tr>
                    <?php endif; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
          <div>
            <!-- Attachments Summary -->
            <?php if (!empty($r['attachments'])): ?>
                <div class="card mb-4">
                    <h3 class="section-title">📎 Attachments</h3>
                    <div class="attachments-grid">
                        <?php foreach (array_slice($r['attachments'], 0, 4) as $attachment): // Show max 4 ?>
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
                </div>
            <?php endif; ?>
          </div>
        </div>
      </article>
      <?php endforeach; ?>
      <?php if(empty($pendingApprovalRows)): ?>
        <p class="muted text-center py-4">No quotes are pending customer approval.</p>
      <?php endif; ?>
    </div>
  </section>

  <!-- Approved & Repairing -->
  <section id="approved-repairing" class="tab-content" role="tabpanel" aria-labelledby="tab-approved-repairing">
    <input type="text" class="search-input" placeholder="🔎 Search..." data-target="list-approved-repairing" aria-label="Search Approved & Repairing requests">
    <div id="list-approved-repairing">
      <?php foreach($approvedRepairingRows as $r):
        $plist   = parts_for($r['id']);
        $invoice = get_invoice($r['id']); // Guaranteed to exist and be 'Approved' by query
        $history = history_for($r['id']);
        // Fallback chain for problem description
        $problemText = $r['problem'] ?? ($r['problem_reported'] ?? ($r['issue_description'] ?? ($r['notes'] ?? '')));
        // Parse problem list (assuming '; ' separator)
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
              <?php if($invoice): ?>
                <span class="chip status-approved">Quote #<?= e($invoice['id']) ?> — <?= e($invoice['quote_status']) ?></span>
              <?php endif; ?>
            </div>
            <div class="text-sm muted mt-1">
              <?= e($r['device_type']) ?><?= $r['brand']?' · <span class="font-medium">'.e($r['brand']).'</span>':'' ?><?= $r['model']?' · <span class="font-medium">'.e($r['model']).'</span>':'' ?>
            </div>
            <div class="text-sm muted mt-1">
              Customer: <span class="font-medium"><?= e($r['cust_name']) ?></span> • <?= e($r['cust_email']) ?><?= $r['cust_phone']?' • '.e($r['cust_phone']):'' ?>
            </div>
          </div>
          <form method="post" onsubmit="return confirm('Send this request to Billing?');">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="rid" value="<?= e($r['id']) ?>">
            <button class="btn primary" name="act" value="complete">Send to Billing</button>
          </form>
        </div>

        <div class="grid-2">
          <div>
            <!-- Problem Reported Summary -->
            <div class="highlight-box">
              <h3 class="section-title">⚠️ Problem Reported</h3>
              <?php if ($problemText): ?>
                <?php if (!empty($problemList) && count($problemList) > 1): ?>
                     <div class="text-sm"><?= implode(', ', array_slice(array_map('e', $problemList), 0, 3)) ?><?= count($problemList) > 3 ? '...' : '' ?></div>
                <?php else: ?>
                     <div class="text-sm"><?= e(implode(', ', $problemList)) ?></div>
                <?php endif; ?>
              <?php else: ?>
                <div class="text-sm muted">No problem description provided.</div>
              <?php endif; ?>
            </div>

            <!-- Approved Parts List -->
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
                      <th class="actions-cell">Action</th> <!-- Allow removal/editing during repair? -->
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach($plist as $p): ?>
                      <tr>
                        <td><?= e($p['item']) ?></td>
                        <td><?= e($p['qty']) ?></td>
                        <td class="muted"><?= e($p['unit']) ?></td>
                        <td class="muted"><?= e($p['remarks']) ?></td>
                        <td class="actions-cell">
                          <!-- Placeholder for potential actions like 'Used', 'Not Needed' etc. -->
                          <span class="text-xs muted">-</span>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                    <?php if(!$plist): ?>
                      <tr><td colspan="5" class="text-center muted py-2">No parts listed.</td></tr>
                    <?php endif; ?>
                  </tbody>
                </table>
              </div>
              <!-- Add part during repair if needed -->
              <form method="post" class="mt-4">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="rid" value="<?= e($r['id']) ?>">

                <div class="form-row">
                  <div>
                    <label for="item-add-<?= e($r['id']) ?>">Add Item</label>
                    <input id="item-add-<?= e($r['id']) ?>" name="item" placeholder="e.g., Extra Cable">
                  </div>
                  <div>
                    <label for="unit-add-<?= e($r['id']) ?>">Unit</label>
                    <input id="unit-add-<?= e($r['id']) ?>" name="unit" placeholder="pcs" value="pcs">
                  </div>
                  <div>
                    <label for="qty-add-<?= e($r['id']) ?>">Qty</label>
                    <input id="qty-add-<?= e($r['id']) ?>" name="qty" type="number" step="0.01" value="1" min="0.01">
                  </div>
                  <div>
                    <label for="remarks-add-<?= e($r['id']) ?>">Remarks</label>
                    <input id="remarks-add-<?= e($r['id']) ?>" name="remarks" placeholder="Reason">
                  </div>
                </div>
                <button class="btn outline" name="act" value="addpart">➕ Add During Repair</button>
              </form>
            </div>

            <!-- Technician Notes -->
            <div class="card">
              <div class="card-header">
                <h3 class="section-title">📝 Technician Notes</h3>
              </div>
              <form method="post">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="rid" value="<?= e($r['id']) ?>">
                <div class="form-group">
                    <label for="note_text-repair-<?= e($r['id']) ?>" class="sr-only">Add Repair Note</label>
                    <textarea id="note_text-repair-<?= e($r['id']) ?>" class="w-full" name="note_text" placeholder="Work progress, findings, additional issues..." rows="3" required></textarea>
                </div>
                <button class="btn subtle" name="act" value="add_note">➕ Add Note</button>
              </form>
            </div>
          </div>
          <div>
            <!-- Attachments Summary -->
            <?php if (!empty($r['attachments'])): ?>
                <div class="card mb-4">
                    <h3 class="section-title">📎 Attachments</h3>
                    <div class="attachments-grid">
                        <?php foreach (array_slice($r['attachments'], 0, 4) as $attachment): // Show max 4 ?>
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
                </div>
            <?php endif; ?>

            <!-- Timeline -->
            <div class="card">
              <h3 class="section-title">🕒 Status & Notes Timeline</h3>
              <div class="timeline">
                <?php if($history): foreach($history as $h): ?>
                <div class="timeline-item">
                  <span class="timeline-dot"></span>
                  <div class="timeline-header">
                    <div class="timeline-status"><?= e($h['status']) ?></div>
                    <div class="text-xs muted"><?= e($h['created_at'] ?? '') ?></div>
                  </div>
                  <?php if($h['note']): ?>
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
      <?php if(empty($approvedRepairingRows)): ?>
        <p class="muted text-center py-4">No requests are currently approved and under repair.</p>
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
// --- Lightbox Functionality (from customer/requests.php) ---
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
    video.muted = false; // Allow sound for videos opened in lightbox
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

// Close on click outside
lightbox.addEventListener('click', (e) => {
  if (e.target === lightbox) closeLightbox();
});

// Close on Escape key
document.addEventListener('keydown', (e) => {
  if (e.key === 'Escape' && lightbox.classList.contains('active')) {
    closeLightbox();
  }
});
// --- End Lightbox Functionality ---

// --- Toggle Problem List Dropdown (Custom) ---
function toggleProblemList(triggerElement, requestId) {
  const dropdown = document.getElementById('problem-list-' + requestId);
  const icon = document.getElementById('dropdown-icon-' + requestId);
  if (dropdown && icon) {
    dropdown.classList.toggle('hidden');
    // Change the arrow icon
    icon.textContent = dropdown.classList.contains('hidden') ? '▼' : '▲';
  }
}
// --- End Toggle Problem List Dropdown ---

// --- Toggle Edit Forms ---
document.querySelectorAll('.toggle-edit').forEach(btn=>{
  btn.addEventListener('click', ()=>{
    const sel = btn.getAttribute('data-target');
    const el = document.querySelector(sel);
    if(!el) return;
    el.classList.toggle('hidden');
  });
});
// --- End Toggle Edit Forms ---

// --- Tab Switching ---
document.querySelectorAll('.tab-btn').forEach(btn=>{
  btn.addEventListener('click', ()=>{
    // Update button states
    document.querySelectorAll('.tab-btn').forEach(b=> {
        b.classList.remove('active');
        b.setAttribute('aria-selected', 'false');
    });
    btn.classList.add('active');
    btn.setAttribute('aria-selected', 'true');

    // Show/hide content
    const id = btn.dataset.tab;
    document.querySelectorAll('.tab-content').forEach(sec=>sec.classList.remove('active'));
    document.getElementById(id).classList.add('active');
  });
});

// --- Live Search ---
function debounce(func, delay) {
  let timer;
  return function () {
    const context = this, args = arguments;
    clearTimeout(timer);
    timer = setTimeout(() => func.apply(context, args), delay);
  };
}
const applySearch = debounce(() => {
  document.querySelectorAll('.search-input').forEach(input=>{
    const term = input.value.toLowerCase().trim();
    const listId = input.dataset.target;
    const list = document.getElementById(listId);
    if(!list) return;
    list.querySelectorAll('.searchable').forEach(card=>{
      // Build a string from data attributes for searching
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