<?php
/**
 * Staff Registration Desk — NexusFix (Enhanced)
 * Incorporates attachment previews, vertical actions, and filters.
 */
declare(strict_types=1);
session_start();
require_once __DIR__.'/../../includes/functions.php';
require_once __DIR__.'/../../includes/guard.php';
require_once __DIR__.'/../../config/db.php';
require_role('staff','admin');

$note = null;
$errors = [];

/**
 * Helper: safely update status + log history (transactional)
 */
function update_status($rid, $newStatus, $noteText, $userId) {
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("UPDATE repair_requests SET status=? WHERE id=?");
        $stmt->execute([$newStatus, $rid]);

        $stmt = $pdo->prepare("INSERT INTO request_status_history (request_id,status,note,changed_by) VALUES (?,?,?,?)");
        $stmt->execute([$rid, $newStatus, $noteText, $userId]);

        $pdo->commit();
        return true;
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Status update failed: " . $e->getMessage());
        return false;
    }
}

/**
 * Helper: add a freeform note to history WITHOUT changing status
 */
function add_history_note($rid, $noteText, $userId){
    $pdo = db();
    $stmt = $pdo->prepare("SELECT status FROM repair_requests WHERE id=?");
    $stmt->execute([$rid]);
    $cur = $stmt->fetchColumn();
    if(!$cur) return false;
    $stmt = $pdo->prepare("INSERT INTO request_status_history (request_id,status,note,changed_by) VALUES (?,?,?,?)");
    return $stmt->execute([$rid,$cur,$noteText,$userId]);
}

// --- Helper function to get attachments for a request ---
function get_request_attachments(int $request_id): array {
    $stmt = db()->prepare("SELECT file_path, file_type FROM request_attachments WHERE request_id = ? ORDER BY uploaded_at ASC");
    $stmt->execute([$request_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// --- Helper function to determine if a file type is an image ---
function is_image_type(string $file_type): bool {
    $image_types = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    return in_array(strtolower($file_type), $image_types);
}

// --- Helper function to determine if a file type is a video ---
function is_video_type(string $file_type): bool {
    $video_types = ['mp4', 'mov', 'avi', 'mkv', 'webm'];
    return in_array(strtolower($file_type), $video_types);
}

// --- Helper function to get the full URL for a file path ---
function get_file_url(string $file_path): string {
    $base = rtrim(dirname(base_url()), '/'); // removes /public
    return $base . '/' . ltrim($file_path, '/');
}

// ---- Workflow state machine -------------------------------------------------
$workflows = [
    'Received' => [
        'accept_dropoff' => ['next'=>'In Repair', 'note'=>'Accepted at Registration → Repair', 'assignment'=>'Repair', 'guard'=>function($req){return $req['service_type']==='dropoff';}],
        'start_pickup'   => ['next'=>'Pickup In Progress', 'note'=>'Staff going for pickup', 'guard'=>function($req){return $req['service_type']==='pickup';}],
        'start_onsite'   => ['next'=>'Onsite In Progress', 'note'=>'Staff going onsite', 'guard'=>function($req){return $req['service_type']==='onsite';}],
        'reject'         => ['next'=>'Rejected', 'note'=>'Rejected by Registration desk']
    ],
    'Pickup In Progress' => [
        'pickup_received' => ['next'=>'Device Received', 'note'=>'Device collected from customer']
    ],
    'Device Received' => [
        'at_warehouse' => ['next'=>'At Warehouse', 'note'=>'Device entered warehouse']
    ],
    'At Warehouse' => [
        'forward_repair' => ['next'=>'In Repair', 'note'=>'Forwarded to Repair desk', 'assignment'=>'Repair']
    ],
    'Onsite In Progress' => [
        'onsite_repair' => ['next'=>'Onsite Repair Started', 'note'=>'Work started onsite']
    ],
    'Onsite Repair Started' => [
        'onsite_done' => ['next'=>'Onsite Completed', 'note'=>'Onsite repair completed']
    ],
    'Onsite Completed' => [
        'onsite_forward_repair'  => ['next'=>'In Repair', 'note'=>'Forwarded to Repair desk for parts listing', 'assignment'=>'Repair'],
        'onsite_forward_billing' => ['next'=>'Billed', 'note'=>'Forwarded to Billing']
    ]
];

// ---- Handle actions ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD']==='POST') {
  if (!csrf_verify($_POST['csrf'] ?? '')) $errors['csrf']='Invalid token';
  $act = $_POST['act'] ?? '';
  $rid = (int)($_POST['rid'] ?? 0);

  if(!$errors){
    $reqStmt = db()->prepare("SELECT * FROM repair_requests WHERE id=?");
    $reqStmt->execute([$rid]);
    $req = $reqStmt->fetch();

    if(!$req){
        $errors['req']="Request not found.";
    } else {
      $nowUser = $_SESSION['user']['id'];

      // Update editable device details
      if ($act==='update') {
        $brand = trim($_POST['brand']??'');
        $model = trim($_POST['model']??'');
        $serial= trim($_POST['serial_no']??'');

        if(!$brand || !$model || !$serial){
            $errors['update'] = "Brand, model, and serial/IMEI are required.";
        } else {
            $stmt = db()->prepare("UPDATE repair_requests SET brand=?, model=?, serial_no=? WHERE id=?");
            if($stmt->execute([$brand,$model,$serial,$rid])){
                $note="Details updated.";
            } else {
                $errors['update'] = "Failed to update device details.";
            }
        }
      }
      // Save onsite work detail as a history note
      elseif ($act==='onsite_save_detail') {
        $detail = trim($_POST['work_detail'] ?? '');
        if(!$detail){
          $errors['detail'] = 'Please enter the onsite repair detail.';
        } else {
          if(add_history_note($rid, 'Onsite work detail: ' . $detail, $nowUser)){
            $note = 'Onsite detail saved.';
          } else {
            $errors['detail'] = 'Failed to save onsite detail.';
          }
        }
      }
      // Workflow transitions via state machine
      elseif (isset($workflows[$req['status']][$act])) {
        $wf = $workflows[$req['status']][$act];
        if (isset($wf['guard']) && is_callable($wf['guard']) && !$wf['guard']($req)) {
            $errors['guard'] = 'Action not allowed for this service type.';
        } else {
            $ok = update_status($rid, $wf['next'], $wf['note'], $nowUser);
            if($ok){
              $note = $wf['note'];
              if (!empty($wf['assignment'])) {
                try {
                  $stmt = db()->prepare("INSERT INTO request_assignments (request_id,desk,assigned_to) VALUES (?,?,?)");
                  $stmt->execute([$rid,$wf['assignment'],$nowUser]);
                } catch(Exception $e){
                  error_log("Assignment insert failed: ".$e->getMessage());
                }
              }
            } else {
              $errors['workflow'] = "Failed to update workflow.";
            }
        }
      }
      else {
        $errors['invalid'] = "Invalid action for current status.";
      }
    }
  }
}

// ---- Load lists (with attachments) -------------------------------------------------------------
// Add attachment fetching to the queries
$newReqs = db()->query("SELECT rr.*,u.name cust_name,u.email cust_email,u.phone cust_phone
  FROM repair_requests rr JOIN users u ON u.id=rr.customer_id
  WHERE rr.status='Received' ORDER BY rr.id DESC")->fetchAll();
// Add attachments to $newReqs
foreach ($newReqs as &$req) {
    $req['attachments'] = get_request_attachments((int)$req['id']);
}
unset($req); // Unset reference variable

$repairReqs = db()->query("SELECT rr.*,u.name cust_name,u.email cust_email,u.phone cust_phone
  FROM repair_requests rr JOIN users u ON u.id=rr.customer_id
  WHERE rr.status='In Repair'
  ORDER BY rr.id DESC")->fetchAll();
// Add attachments to $repairReqs
foreach ($repairReqs as &$req) {
    $req['attachments'] = get_request_attachments((int)$req['id']);
}
unset($req);

// Split pickup and onsite buckets
$pickupAll = db()->query("SELECT rr.*,u.name cust_name,u.email cust_email,u.phone cust_phone
  FROM repair_requests rr JOIN users u ON u.id=rr.customer_id
  WHERE rr.status IN ('Pickup In Progress','Device Received','At Warehouse')
  ORDER BY rr.id DESC")->fetchAll();
// Add attachments to $pickupAll
foreach ($pickupAll as &$req) {
    $req['attachments'] = get_request_attachments((int)$req['id']);
}
unset($req);

$onsiteAll = db()->query("SELECT rr.*,u.name cust_name,u.email cust_email,u.phone cust_phone
  FROM repair_requests rr JOIN users u ON u.id=rr.customer_id
  WHERE rr.status IN ('Onsite In Progress','Onsite Repair Started','Onsite Completed')
  ORDER BY rr.id DESC")->fetchAll();
// Add attachments to $onsiteAll
foreach ($onsiteAll as &$req) {
    $req['attachments'] = get_request_attachments((int)$req['id']);
}
unset($req);

$historyReqs = db()->query("SELECT rr.*,u.name cust_name,u.email cust_email
  FROM repair_requests rr JOIN users u ON u.id=rr.customer_id
  WHERE rr.status IN ('Rejected','Cancelled','Billed','Delivered')
  ORDER BY rr.id DESC LIMIT 30")->fetchAll();
// Add attachments to $historyReqs
foreach ($historyReqs as &$req) {
    $req['attachments'] = get_request_attachments((int)$req['id']);
}
unset($req);

// History loader
function fetch_history($rid){
  $h = db()->prepare("SELECT h.*,u.name staff FROM request_status_history h
                      LEFT JOIN users u ON u.id=h.changed_by
                      WHERE request_id=? ORDER BY h.changed_at ASC");
  $h->execute([$rid]);
  return $h->fetchAll();
}
?>
<!doctype html>
<html lang="en" data-theme="dark"> <!-- Added theme attribute -->
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Registration Desk</title>
<link rel="stylesheet" href="../../assets/css/styles.css">
<style>
/* --- Base Theme Variables --- */
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

/* --- Layout & Base Styles --- */
body {
  background: var(--bg);
  color: var(--text);
  font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
  line-height: 1.5;
  margin: 0;
  padding: 0;
}
main.how {
  max-width: 1200px; /* Increased max width */
  margin: 2rem auto;
  padding: 1rem;
}
h2 {
  font-size: 1.75rem;
  font-weight: 600;
  margin-bottom: 1rem;
}

/* --- Tabs --- */
.tabs {
  display:flex;
  gap:10px;
  margin-bottom:1rem;
  flex-wrap:wrap;
}
.tab-btn {
  padding:8px 16px;
  border-radius:8px;
  border:1px solid var(--field-border); /* Changed to field-border */
  background: var(--card); /* Changed to card background */
  cursor:pointer;
  transition:all .2s ease;
  color: var(--text); /* Changed to text color */
}
.tab-btn:hover {
  background: var(--field-border); /* Changed to field-border */
}
.tab-btn.active {
  background:linear-gradient(90deg,#6366f1,#ec4899,#f59e0b);
  color:#fff;
  border:none;
  box-shadow:0 2px 6px rgba(0,0,0,.15);
}
.tab-content { display:none; }
.tab-content.active { display:block; animation:fadeIn .3s ease; }

/* --- Subtabs --- */
.subtabs {
  display:flex;
  gap:8px;
  margin:.75rem 0;
  flex-wrap:wrap;
}
.subtab-btn {
  padding:6px 12px;
  border-radius:999px;
  border:1px solid var(--field-border); /* Changed to field-border */
  background: var(--card); /* Changed to card background */
  cursor:pointer;
  color: var(--text); /* Changed to text color */
}
.subtab-btn:hover {
  background: var(--field-border); /* Changed to field-border */
}
.subtab-btn.active {
  background:#111827;
  color:#fff;
}
.subtab-content { display:none; }
.subtab-content.active { display:block; }

/* --- Timeline & Details --- */
.timeline {
  margin-top:8px;
  font-size:.9em;
  color:var(--muted);
  display:none;
  padding-left: 1rem;
  border-left: 2px solid var(--field-border); /* Added border */
  list-style: none; /* Remove default list style */
}
.timeline.show { display:block; }
.timeline li {
  position: relative;
  padding: 0.5rem 0;
}
.timeline li::before {
  content: '';
  position: absolute;
  left: -13px; /* Adjust based on border width */
  top: 10px;
  width: 8px;
  height: 8px;
  border-radius: 50%;
  background: var(--primary); /* Use primary color for dot */
  border: 2px solid var(--card); /* Border same as card background */
}
.timeline-date {
  font-size: 0.8rem;
  color: var(--muted);
}

.edit-fields {
  display:none;
  grid-template-columns:repeat(auto-fit, minmax(150px, 1fr));
  gap:8px;
  margin-top:10px;
  margin-bottom: 1rem; /* Add space below edit fields */
}
.edit-fields.show { display:grid; }

.details {
  margin:8px 0;
  font-size:.9em;
  color:var(--muted);
}
/* --- Filters --- */
.filters {
  display: flex;
  flex-wrap: wrap;
  gap: 12px;
  margin-bottom: 1.5rem;
  align-items: center;
}
.filters input, .filters select {
  padding: 0.65rem 0.9rem;
  border: 1px solid var(--field-border);
  border-radius: 12px;
  background: var(--card);
  color: var(--text);
  font-size: 0.95rem;
}
.filters input:focus, .filters select:focus {
  outline: none;
  border-color: var(--primary);
}
.search-input {
  width: 100%;
  padding: 0.65rem 0.9rem; /* Use consistent padding */
  border: 1px solid var(--field-border);
  border-radius: 12px;
  background: var(--card);
  color: var(--text);
  font-size: 0.95rem;
  margin-bottom: 12px; /* Keep margin */
}
.textarea {
  width:100%;
  min-height:100px;
  padding:8px;
  border:1px solid var(--field-border); /* Changed to field-border */
  border-radius: 12px; /* Added border radius */
  background: var(--card); /* Added background */
  color: var(--text); /* Added text color */
}

/* --- Request Card Enhancements --- */
.card {
  background: var(--card);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  padding: 1rem;
  margin-bottom: 1rem;
  box-shadow: var(--shadow-sm);
  transition: var(--transition);
  display: flex; /* Make card a flex container */
  flex-wrap: wrap; /* Allow wrapping */
  gap: 1rem; /* Space between columns */
}
.card:hover {
  transform: translateY(-2px);
  box-shadow: var(--shadow);
}

/* --- Left Column (Details) --- */
.card-details {
  flex: 2; /* Take more space */
  min-width: 300px; /* Minimum width */
}
.card h3 {
  margin: 0 0 8px;
  font-size: 1.15rem;
  font-weight: 600;
}
.card p {
  margin: 0 0 0.5rem; /* Reduce margin */
}

/* --- Right Column (Actions & Attachments) --- */
.card-actions-attachments {
  flex: 1; /* Take less space */
  min-width: 200px; /* Minimum width */
  display: flex;
  flex-direction: column;
  gap: 1rem; /* Space between sections */
}

/* --- Action Buttons (Vertical) --- */
.card-actions {
  display: flex;
  flex-direction: column;
  gap: 8px; /* Space between buttons */
}
.card-actions .btn {
  width: 100%; /* Full width buttons */
  justify-content: center; /* Center text/icons */
  text-align: center;
  padding: 0.6rem 1rem; /* Consistent padding */
  border-radius: 12px; /* Consistent border radius */
  font-weight: 600; /* Consistent font weight */
  transition: var(--transition); /* Consistent transition */
}
.card-actions .btn.primary {
  background: var(--primary);
  color: white;
}
.card-actions .btn.primary:hover {
  background: #4f9cf9;
  transform: translateY(-1px);
}
.card-actions .btn.outline {
  background: transparent;
  color: var(--muted);
  border: 1px solid var(--field-border);
}
.card-actions .btn.outline:hover {
  background: rgba(255,255,255,.06);
}
.card-actions .btn.subtle {
  background: rgba(255,255,255,.06);
  color: var(--text);
  border: 1px solid transparent;
}
.card-actions .btn.subtle:hover {
  background: rgba(255,255,255,.1);
}

/* --- Attachments --- */
.attachments-grid {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-top: 0.5rem; /* Add space above attachments */
}
.attachment-item {
    position: relative;
    width: 80px; /* Slightly smaller */
    height: 80px; /* Slightly smaller */
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
    font-size: 0.6rem; /* Smaller font */
    font-weight: bold;
}
.attachment-filename {
    position: absolute;
    bottom: 0;
    left: 0;
    right: 0;
    background: rgba(0,0,0,0.7);
    color: white;
    font-size: 0.55rem; /* Smaller font */
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
    margin-top: 0.5rem; /* Add space above */
}

/* --- Lightbox Modal --- */
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

/* --- Messages --- */
.banner {
  padding: 1rem;
  border-radius: 12px;
  margin-bottom: 1.5rem;
  font-size: 0.95rem;
}
.banner.success {
  background: rgba(52, 211, 153, 0.15);
  border: 1px solid rgba(52, 211, 153, 0.3);
  color: var(--success);
}
.banner.error {
  background: rgba(248, 113, 113, 0.15);
  border: 1px solid rgba(248, 113, 113, 0.3);
  color: var(--danger);
}
.banner ul {
  margin: 0.5rem 0 0 1.2rem;
  padding: 0;
}

@media (max-width: 768px) {
  .card {
    flex-direction: column; /* Stack columns on small screens */
  }
  .card-details, .card-actions-attachments {
    min-width: 100%; /* Full width on small screens */
  }
}

@media (prefers-reduced-motion: reduce) {
  * {
    transition: none !important;
    animation: none !important;
  }
}
</style>
</head>
<body>
<?php require __DIR__.'/../../includes/header.php'; ?>
<main class="how">
  <h2>Registration Desk</h2>
  <?php if($note): ?><div class="banner success"><?= e($note) ?></div><?php endif; ?>
  <?php if($errors): ?>
    <div class="banner error">
        <?php foreach($errors as $key => $m): ?>
            <?php if ($key !== 'csrf'): // Don't show CSRF error inline ?>
                <div><?= e($m) ?></div>
            <?php endif; ?>
        <?php endforeach; ?>
        <?php if (!empty($errors['csrf'])): ?>
            <div><?= e($errors['csrf']) ?></div>
        <?php endif; ?>
    </div>
  <?php endif; ?>

  <div class="tabs">
    <button class="tab-btn active" data-tab="new">🆕 New Requests</button>
    <button class="tab-btn" data-tab="repair">🔧 Repair Desk</button>
    <button class="tab-btn" data-tab="pickup">🚚 Pickup</button>
    <button class="tab-btn" data-tab="onsite">🛠️ Onsite</button>
    <button class="tab-btn" data-tab="history">📜 History</button>
  </div>

  <!-- New -->
  <div id="new" class="tab-content active">
    <!-- Filters for New Requests -->
    <div class="filters">
        <input type="text" class="search-input" placeholder="🔎 Search..." data-target="list-new" id="search-new">
        <select class="status-filter" data-target="list-new" id="status-filter-new">
            <option value="">All Service Types</option>
            <option value="dropoff">Drop-off</option>
            <option value="pickup">Pickup</option>
            <option value="onsite">On-site</option>
        </select>
    </div>
    <?php if(!$newReqs): ?><p>No new requests.</p><?php endif; ?>
    <div class="list" id="list-new">
      <?php foreach($newReqs as $r): ?>
        <article class="card searchable" data-service-type="<?= strtolower(e($r['service_type'])) ?>">
          <div class="card-details">
            <h3><?= e($r['ticket_code']) ?> — <?= e($r['device_type']) ?></h3>
            <p><strong>Created:</strong> <?= e($r['created_at']) ?></p>
            <div class="details">
              <div><strong>Customer:</strong> <?= e($r['cust_name']) ?> • <?= e($r['cust_email']) ?> <?= $r['cust_phone']?'• '.e($r['cust_phone']):'' ?></div>
              <div><strong>Brand:</strong> <?= e($r['brand']) ?> | <strong>Model:</strong> <?= e($r['model']) ?> | <strong>Serial/IMEI:</strong> <?= e($r['serial_no']) ?></div>
              <div><strong>Service:</strong> <?= ucfirst(e($r['service_type'])) ?> | <strong>Contact:</strong> <?= e($r['preferred_contact']) ?></div>
            </div>
            <p><strong>Issue:</strong> <?= nl2br(e($r['issue_description'])) ?></p>
            <form method="post">
              <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
              <input type="hidden" name="rid" value="<?= e($r['id']) ?>">
              <button type="button" class="btn subtle toggle-edit">✎ Edit Details</button>
              <div class="edit-fields">
                <input name="brand" placeholder="Brand" value="<?= e($r['brand']) ?>">
                <input name="model" placeholder="Model" value="<?= e($r['model']) ?>">
                <input name="serial_no" placeholder="Serial/IMEI" value="<?= e($r['serial_no']) ?>">
                <button class="btn subtle" name="act" value="update">💾 Save</button>
              </div>
            </form>
          </div>
          <div class="card-actions-attachments">
              <!-- Action Buttons (Vertical) -->
              <div class="card-actions">
                <form method="post" style="display: contents;"> <!-- Use display: contents to avoid extra div -->
                  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                  <input type="hidden" name="rid" value="<?= e($r['id']) ?>">
                  <?php if($r['service_type']==='dropoff'): ?>
                    <button class="btn primary" name="act" value="accept_dropoff">Forward to Repair</button>
                  <?php elseif($r['service_type']==='pickup'): ?>
                    <button class="btn primary" name="act" value="start_pickup">Start Pickup</button>
                  <?php elseif($r['service_type']==='onsite'): ?>
                    <button class="btn primary" name="act" value="start_onsite">Start Onsite</button>
                  <?php endif; ?>
                  <button class="btn outline" name="act" value="reject">Reject</button>
                </form>
              </div>

              <!-- Attachments -->
              <div>
                <h4 style="margin: 0 0 0.5rem; font-size: 1rem;">Attachments</h4>
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
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- Repair Desk -->
  <div id="repair" class="tab-content">
    <input type="text" class="search-input" placeholder="🔎 Search..." data-target="list-repair">
    <?php if(!$repairReqs): ?><p>No items in repair.</p><?php endif; ?>
    <div class="list" id="list-repair">
      <?php foreach($repairReqs as $r): ?>
        <article class="card searchable">
          <div class="card-details">
            <h3><?= e($r['ticket_code']) ?> — <?= e($r['device_type']) ?> <?= e($r['brand']) ?> <?= e($r['model']) ?></h3>
            <p><strong>Status:</strong> <?= e($r['status']) ?> | <strong>Created:</strong> <?= e($r['created_at']) ?></p>
            <button type="button" class="btn subtle toggle-timeline">📜 View Timeline</button>
            <div class="timeline">
              <?php foreach(fetch_history($r['id']) as $ev): ?>
                <li class="timeline-item">
                  <div><strong><?= e($ev['status']) ?></strong></div>
                  <?php if ($ev['note']): ?>
                    <div style="font-size:0.9rem; margin-top:2px;"><?= e($ev['note']) ?></div>
                  <?php endif; ?>
                  <div class="timeline-date">
                    <?= (new DateTime($ev['changed_at']))->format('M j, g:i A') ?>
                  </div>
                </li>
              <?php endforeach; ?>
            </ol>
          </div>
          <div class="card-actions-attachments">
              <!-- Attachments -->
              <div>
                <h4 style="margin: 0 0 0.5rem; font-size: 1rem;">Attachments</h4>
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
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- Pickup -->
  <div id="pickup" class="tab-content">
    <div class="subtabs">
      <button class="subtab-btn active" data-subtab="pickup-going">🚚 Going to Pickup</button>
      <button class="subtab-btn" data-subtab="pickup-received">📦 Device Received</button>
      <button class="subtab-btn" data-subtab="pickup-warehouse">🏭 At Warehouse</button>
    </div>

    <div id="pickup-going" class="subtab-content active">
      <input type="text" class="search-input" placeholder="🔎 Search..." data-target="list-pickup-going">
      <div class="list" id="list-pickup-going">
        <?php foreach($pickupAll as $r): if($r['status']!=='Pickup In Progress') continue; ?>
          <article class="card searchable">
            <div class="card-details">
              <h3><?= e($r['ticket_code']) ?> — <?= e($r['device_type']) ?> <?= e($r['brand']) ?> <?= e($r['model']) ?></h3>
              <p><strong>Status:</strong> <?= e($r['status']) ?> | <strong>Created:</strong> <?= e($r['created_at']) ?></p>
              <form method="post">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="rid" value="<?= e($r['id']) ?>">
                <button class="btn primary" name="act" value="pickup_received">Mark as Received</button>
              </form>
              <button type="button" class="btn subtle toggle-timeline">📜 View Timeline</button>
              <div class="timeline">
                <?php foreach(fetch_history($r['id']) as $ev): ?>
                  <li class="timeline-item">
                    <div><strong><?= e($ev['status']) ?></strong></div>
                    <?php if ($ev['note']): ?>
                      <div style="font-size:0.9rem; margin-top:2px;"><?= e($ev['note']) ?></div>
                    <?php endif; ?>
                    <div class="timeline-date">
                      <?= (new DateTime($ev['changed_at']))->format('M j, g:i A') ?>
                    </div>
                  </li>
                <?php endforeach; ?>
              </div>
            </div>
            <div class="card-actions-attachments">
                <!-- Attachments -->
                <div>
                  <h4 style="margin: 0 0 0.5rem; font-size: 1rem;">Attachments</h4>
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
            </div>
          </article>
        <?php endforeach; ?>
        <?php if(!$pickupAll || !array_filter($pickupAll, fn($x)=>$x['status']==='Pickup In Progress')): ?><p>No items.</p><?php endif; ?>
      </div>
    </div>

    <div id="pickup-received" class="subtab-content">
      <input type="text" class="search-input" placeholder="🔎 Search..." data-target="list-pickup-received">
      <div class="list" id="list-pickup-received">
        <?php foreach($pickupAll as $r): if($r['status']!=='Device Received') continue; ?>
          <article class="card searchable">
            <div class="card-details">
              <h3><?= e($r['ticket_code']) ?> — <?= e($r['device_type']) ?> <?= e($r['brand']) ?> <?= e($r['model']) ?></h3>
              <p><strong>Status:</strong> <?= e($r['status']) ?> | <strong>Created:</strong> <?= e($r['created_at']) ?></p>
              <form method="post">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="rid" value="<?= e($r['id']) ?>">
                <button class="btn primary" name="act" value="at_warehouse">At Warehouse</button>
              </form>
              <button type="button" class="btn subtle toggle-timeline">📜 View Timeline</button>
              <div class="timeline">
                <?php foreach(fetch_history($r['id']) as $ev): ?>
                  <li class="timeline-item">
                    <div><strong><?= e($ev['status']) ?></strong></div>
                    <?php if ($ev['note']): ?>
                      <div style="font-size:0.9rem; margin-top:2px;"><?= e($ev['note']) ?></div>
                    <?php endif; ?>
                    <div class="timeline-date">
                      <?= (new DateTime($ev['changed_at']))->format('M j, g:i A') ?>
                    </div>
                  </li>
                <?php endforeach; ?>
              </div>
            </div>
            <div class="card-actions-attachments">
                <!-- Attachments -->
                <div>
                  <h4 style="margin: 0 0 0.5rem; font-size: 1rem;">Attachments</h4>
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
            </div>
          </article>
        <?php endforeach; ?>
        <?php if(!$pickupAll || !array_filter($pickupAll, fn($x)=>$x['status']==='Device Received')): ?><p>No items.</p><?php endif; ?>
      </div>
    </div>

    <div id="pickup-warehouse" class="subtab-content">
      <input type="text" class="search-input" placeholder="🔎 Search..." data-target="list-pickup-warehouse">
      <div class="list" id="list-pickup-warehouse">
        <?php foreach($pickupAll as $r): if($r['status']!=='At Warehouse') continue; ?>
          <article class="card searchable">
            <div class="card-details">
              <h3><?= e($r['ticket_code']) ?> — <?= e($r['device_type']) ?> <?= e($r['brand']) ?> <?= e($r['model']) ?></h3>
              <p><strong>Status:</strong> <?= e($r['status']) ?> | <strong>Created:</strong> <?= e($r['created_at']) ?></p>
              <form method="post">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="rid" value="<?= e($r['id']) ?>">
                <button class="btn primary" name="act" value="forward_repair">Forward to Repair</button>
              </form>
              <button type="button" class="btn subtle toggle-timeline">📜 View Timeline</button>
              <div class="timeline">
                <?php foreach(fetch_history($r['id']) as $ev): ?>
                  <li class="timeline-item">
                    <div><strong><?= e($ev['status']) ?></strong></div>
                    <?php if ($ev['note']): ?>
                      <div style="font-size:0.9rem; margin-top:2px;"><?= e($ev['note']) ?></div>
                    <?php endif; ?>
                    <div class="timeline-date">
                      <?= (new DateTime($ev['changed_at']))->format('M j, g:i A') ?>
                    </div>
                  </li>
                <?php endforeach; ?>
              </div>
            </div>
            <div class="card-actions-attachments">
                <!-- Attachments -->
                <div>
                  <h4 style="margin: 0 0 0.5rem; font-size: 1rem;">Attachments</h4>
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
            </div>
          </article>
        <?php endforeach; ?>
        <?php if(!$pickupAll || !array_filter($pickupAll, fn($x)=>$x['status']==='At Warehouse')): ?><p>No items.</p><?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Onsite -->
  <div id="onsite" class="tab-content">
    <div class="subtabs">
      <button class="subtab-btn active" data-subtab="onsite-going">🛠️ Going Onsite</button>
      <button class="subtab-btn" data-subtab="onsite-started">🔧 Repair Started</button>
      <button class="subtab-btn" data-subtab="onsite-completed">✅ Onsite Completed</button>
    </div>

    <div id="onsite-going" class="subtab-content active">
      <input type="text" class="search-input" placeholder="🔎 Search..." data-target="list-onsite-going">
      <div class="list" id="list-onsite-going">
        <?php foreach($onsiteAll as $r): if($r['status']!=='Onsite In Progress') continue; ?>
          <article class="card searchable">
            <div class="card-details">
              <h3><?= e($r['ticket_code']) ?> — <?= e($r['device_type']) ?> <?= e($r['brand']) ?> <?= e($r['model']) ?></h3>
              <p><strong>Status:</strong> <?= e($r['status']) ?> | <strong>Created:</strong> <?= e($r['created_at']) ?></p>
              <form method="post">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="rid" value="<?= e($r['id']) ?>">
                <button class="btn primary" name="act" value="onsite_repair">Repair Started</button>
              </form>
              <button type="button" class="btn subtle toggle-timeline">📜 View Timeline</button>
              <div class="timeline">
                <?php foreach(fetch_history($r['id']) as $ev): ?>
                  <li class="timeline-item">
                    <div><strong><?= e($ev['status']) ?></strong></div>
                    <?php if ($ev['note']): ?>
                      <div style="font-size:0.9rem; margin-top:2px;"><?= e($ev['note']) ?></div>
                    <?php endif; ?>
                    <div class="timeline-date">
                      <?= (new DateTime($ev['changed_at']))->format('M j, g:i A') ?>
                    </div>
                  </li>
                <?php endforeach; ?>
              </div>
            </div>
            <div class="card-actions-attachments">
                <!-- Attachments -->
                <div>
                  <h4 style="margin: 0 0 0.5rem; font-size: 1rem;">Attachments</h4>
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
            </div>
          </article>
        <?php endforeach; ?>
        <?php if(!$onsiteAll || !array_filter($onsiteAll, fn($x)=>$x['status']==='Onsite In Progress')): ?><p>No items.</p><?php endif; ?>
      </div>
    </div>

    <div id="onsite-started" class="subtab-content">
      <input type="text" class="search-input" placeholder="🔎 Search..." data-target="list-onsite-started">
      <div class="list" id="list-onsite-started">
        <?php foreach($onsiteAll as $r): if($r['status']!=='Onsite Repair Started') continue; ?>
          <article class="card searchable">
            <div class="card-details">
              <h3><?= e($r['ticket_code']) ?> — <?= e($r['device_type']) ?> <?= e($r['brand']) ?> <?= e($r['model']) ?></h3>
              <p><strong>Status:</strong> <?= e($r['status']) ?> | <strong>Created:</strong> <?= e($r['created_at']) ?></p>
              <form method="post">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="rid" value="<?= e($r['id']) ?>">
                <button class="btn primary" name="act" value="onsite_done">Mark Completed</button>
              </form>
              <button type="button" class="btn subtle toggle-timeline">📜 View Timeline</button>
              <div class="timeline">
                <?php foreach(fetch_history($r['id']) as $ev): ?>
                  <li class="timeline-item">
                    <div><strong><?= e($ev['status']) ?></strong></div>
                    <?php if ($ev['note']): ?>
                      <div style="font-size:0.9rem; margin-top:2px;"><?= e($ev['note']) ?></div>
                    <?php endif; ?>
                    <div class="timeline-date">
                      <?= (new DateTime($ev['changed_at']))->format('M j, g:i A') ?>
                    </div>
                  </li>
                <?php endforeach; ?>
              </div>
            </div>
            <div class="card-actions-attachments">
                <!-- Attachments -->
                <div>
                  <h4 style="margin: 0 0 0.5rem; font-size: 1rem;">Attachments</h4>
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
            </div>
          </article>
        <?php endforeach; ?>
        <?php if(!$onsiteAll || !array_filter($onsiteAll, fn($x)=>$x['status']==='Onsite Repair Started')): ?><p>No items.</p><?php endif; ?>
      </div>
    </div>

    <div id="onsite-completed" class="subtab-content">
      <input type="text" class="search-input" placeholder="🔎 Search..." data-target="list-onsite-completed">
      <div class="list" id="list-onsite-completed">
        <?php foreach($onsiteAll as $r): if($r['status']!=='Onsite Completed') continue; ?>
          <article class="card searchable">
            <div class="card-details">
              <h3><?= e($r['ticket_code']) ?> — <?= e($r['device_type']) ?> <?= e($r['brand']) ?> <?= e($r['model']) ?></h3>
              <p><strong>Status:</strong> <?= e($r['status']) ?> | <strong>Created:</strong> <?= e($r['created_at']) ?></p>
              <p><em>Fill onsite repair detail, then forward to Repair desk (for parts listing) or straight to Billing.</em></p>
              <form method="post" style="margin-top:.5rem">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="rid" value="<?= e($r['id']) ?>">
                <textarea class="textarea" name="work_detail" placeholder="Work performed, diagnostics, parts used, recommendations..."></textarea>
                <div style="display:flex; gap:8px; margin-top:8px; flex-wrap:wrap;">
                  <button class="btn subtle" name="act" value="onsite_save_detail">💾 Save Detail</button>
                  <button class="btn primary" name="act" value="onsite_forward_repair">➡️ Forward to Repair</button>
                  <button class="btn outline" name="act" value="onsite_forward_billing">💸 Forward to Billing</button>
                </div>
              </form>
              <button type="button" class="btn subtle toggle-timeline">📜 View Timeline</button>
              <div class="timeline">
                <?php foreach(fetch_history($r['id']) as $ev): ?>
                  <li class="timeline-item">
                    <div><strong><?= e($ev['status']) ?></strong></div>
                    <?php if ($ev['note']): ?>
                      <div style="font-size:0.9rem; margin-top:2px;"><?= e($ev['note']) ?></div>
                    <?php endif; ?>
                    <div class="timeline-date">
                      <?= (new DateTime($ev['changed_at']))->format('M j, g:i A') ?>
                    </div>
                  </li>
                <?php endforeach; ?>
              </div>
            </div>
            <div class="card-actions-attachments">
                <!-- Attachments -->
                <div>
                  <h4 style="margin: 0 0 0.5rem; font-size: 1rem;">Attachments</h4>
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
            </div>
          </article>
        <?php endforeach; ?>
        <?php if(!$onsiteAll || !array_filter($onsiteAll, fn($x)=>$x['status']==='Onsite Completed')): ?><p>No items.</p><?php endif; ?>
      </div>
    </div>
  </div>

  <!-- History -->
  <div id="history" class="tab-content">
    <input type="text" class="search-input" placeholder="🔎 Search..." data-target="list-history">
    <?php if(!$historyReqs): ?><p>No history yet.</p><?php endif; ?>
    <div class="list" id="list-history">
      <?php foreach($historyReqs as $r): ?>
        <article class="card searchable">
          <div class="card-details">
            <h3><?= e($r['ticket_code']) ?> — <?= e($r['device_type']) ?> <?= e($r['brand']) ?> <?= e($r['model']) ?></h3>
            <p><strong>Final Status:</strong> <?= e($r['status']) ?> | <strong>Created:</strong> <?= e($r['created_at']) ?></p>
            <button type="button" class="btn subtle toggle-timeline">📜 View Timeline</button>
            <div class="timeline">
              <?php foreach(fetch_history($r['id']) as $ev): ?>
                <li class="timeline-item">
                  <div><strong><?= e($ev['status']) ?></strong></div>
                  <?php if ($ev['note']): ?>
                    <div style="font-size:0.9rem; margin-top:2px;"><?= e($ev['note']) ?></div>
                  <?php endif; ?>
                  <div class="timeline-date">
                    <?= (new DateTime($ev['changed_at']))->format('M j, g:i A') ?>
                  </div>
                </li>
              <?php endforeach; ?>
            </div>
          </div>
          <div class="card-actions-attachments">
              <!-- Attachments -->
              <div>
                <h4 style="margin: 0 0 0.5rem; font-size: 1rem;">Attachments</h4>
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
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  </div>
</main>

<!-- Lightbox Modal -->
<div id="lightbox" class="lightbox">
  <button class="lightbox-close" onclick="closeLightbox()">×</button>
  <div id="lightbox-content" class="lightbox-content"></div>
  <div id="lightbox-filename" class="lightbox-filename"></div>
</div>

<script>
// --- Lightbox Functionality ---
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

// --- Tab Switching ---
document.querySelectorAll('.tab-btn').forEach(btn=>{
  btn.addEventListener('click',()=>{
    document.querySelectorAll('.tab-btn').forEach(b=>b.classList.remove('active'));
    btn.classList.add('active');
    const id=btn.dataset.tab;
    document.querySelectorAll('.tab-content').forEach(tc=>tc.classList.remove('active'));
    document.getElementById(id).classList.add('active');
  });
});
// --- End Tab Switching ---

// --- Subtab Switching (Event Delegation) ---
document.addEventListener('click',e=>{
  if(e.target.classList.contains('toggle-timeline')){
    e.target.nextElementSibling.classList.toggle('show');
  }
  if(e.target.classList.contains('toggle-edit')){
    e.target.closest('form').querySelector('.edit-fields').classList.toggle('show');
  }
  if(e.target.classList.contains('subtab-btn')){
    const wrap = e.target.closest('.tab-content');
    wrap.querySelectorAll('.subtab-btn').forEach(b=>b.classList.remove('active'));
    e.target.classList.add('active');
    const id = e.target.dataset.subtab;
    wrap.querySelectorAll('.subtab-content').forEach(c=>c.classList.remove('active'));
    wrap.querySelector('#'+id).classList.add('active');
  }
});
// --- End Subtab Switching ---

// --- Search & Filter ---
function debounce(func, delay) {
  let timer;
  return function () {
    const context = this, args = arguments;
    clearTimeout(timer);
    timer = setTimeout(() => func.apply(context, args), delay);
  };
}

const applyFilters = debounce(() => {
  // Apply filters to "New Requests" tab
  const searchNewInput = document.getElementById('search-new');
  const statusNewFilter = document.getElementById('status-filter-new');
  const newItems = document.querySelectorAll('#list-new .searchable');

  if (searchNewInput && statusNewFilter) {
    const q = searchNewInput.value.toLowerCase().trim();
    const status = statusNewFilter.value.toLowerCase().trim();

    newItems.forEach(item => {
      const text = item.innerText.toLowerCase();
      const matchText = !q || text.includes(q);
      const matchStatus = !status || item.dataset.serviceType === status;

      item.style.display = (matchText && matchStatus) ? 'flex' : 'none'; // Changed to 'flex' for card layout
    });
  }

  // Apply generic search to other lists
  document.querySelectorAll('.search-input:not(#search-new)').forEach(input => {
    const term = input.value.toLowerCase().trim();
    const targetId = input.dataset.target;
    const targetList = document.getElementById(targetId);
    if (targetList) {
      targetList.querySelectorAll('.searchable').forEach(card => {
        const txt = card.innerText.toLowerCase();
        card.style.display = txt.includes(term) ? 'flex' : 'none'; // Changed to 'flex' for card layout
      });
    }
  });
}, 150);

// Attach listeners
document.getElementById('search-new')?.addEventListener('input', applyFilters);
document.getElementById('status-filter-new')?.addEventListener('change', applyFilters);
document.querySelectorAll('.search-input:not(#search-new)').forEach(input => {
  input.addEventListener('input', applyFilters);
});
// --- End Search & Filter ---
</script>
<script src="../../assets/js/app.js"></script>
</body>
</html>