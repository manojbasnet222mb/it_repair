<?php
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

// ---- Load lists -------------------------------------------------------------
$newReqs = db()->query("SELECT rr.*,u.name cust_name,u.email cust_email,u.phone cust_phone
  FROM repair_requests rr JOIN users u ON u.id=rr.customer_id
  WHERE rr.status='Received' ORDER BY rr.id DESC")->fetchAll();

$repairReqs = db()->query("SELECT rr.*,u.name cust_name,u.email cust_email,u.phone cust_phone
  FROM repair_requests rr JOIN users u ON u.id=rr.customer_id
  WHERE rr.status='In Repair'
  ORDER BY rr.id DESC")->fetchAll();

// Split pickup and onsite buckets
$pickupAll = db()->query("SELECT rr.*,u.name cust_name,u.email cust_email,u.phone cust_phone
  FROM repair_requests rr JOIN users u ON u.id=rr.customer_id
  WHERE rr.status IN ('Pickup In Progress','Device Received','At Warehouse')
  ORDER BY rr.id DESC")->fetchAll();

$onsiteAll = db()->query("SELECT rr.*,u.name cust_name,u.email cust_email,u.phone cust_phone
  FROM repair_requests rr JOIN users u ON u.id=rr.customer_id
  WHERE rr.status IN ('Onsite In Progress','Onsite Repair Started','Onsite Completed')
  ORDER BY rr.id DESC")->fetchAll();

$historyReqs = db()->query("SELECT rr.*,u.name cust_name,u.email cust_email
  FROM repair_requests rr JOIN users u ON u.id=rr.customer_id
  WHERE rr.status IN ('Rejected','Cancelled','Billed','Delivered')
  ORDER BY rr.id DESC LIMIT 30")->fetchAll();

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
<html>
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Registration Desk</title>
<link rel="stylesheet" href="../../assets/css/styles.css">
<style>
.tabs { display:flex; gap:10px; margin-bottom:1rem; flex-wrap:wrap; }
.tab-btn { padding:8px 16px; border-radius:8px; border:1px solid var(--line); background:#f3f4f6; cursor:pointer; transition:all .2s ease; color:#374151; }
.tab-btn:hover { background:#e5e7eb; }
.tab-btn.active { background:linear-gradient(90deg,#6366f1,#ec4899,#f59e0b); color:#fff; border:none; box-shadow:0 2px 6px rgba(0,0,0,.15); }
.tab-content { display:none; }
.tab-content.active { display:block; animation:fadeIn .3s ease; }

/* Subtabs */
.subtabs { display:flex; gap:8px; margin:.75rem 0; flex-wrap:wrap; }
.subtab-btn { padding:6px 12px; border-radius:999px; border:1px solid var(--line); background:#f9fafb; cursor:pointer; }
.subtab-btn.active { background:#111827; color:#fff; }
.subtab-content { display:none; }
.subtab-content.active { display:block; }

.timeline { margin-top:8px; font-size:.9em; color:var(--muted); display:none; }
.timeline.show { display:block; }
.timeline div { margin-bottom:4px; }

.edit-fields { display:none; grid-template-columns:repeat(auto-fit, minmax(150px, 1fr)); gap:8px; margin-top:10px; }
.edit-fields.show { display:grid; }

.details { margin:8px 0; font-size:.9em; color:var(--muted); }
.search-input { width:100%; padding:8px 12px; margin-bottom:12px; border:1px solid var(--line); border-radius:8px; }
.textarea { width:100%; min-height:100px; padding:8px; border:1px solid var(--line); border-radius:8px; }
</style>
</head>
<body>
<?php require __DIR__.'/../../includes/header.php'; ?>
<main class="how">
  <h2>Registration Desk</h2>
  <?php if($note): ?><div class="banner success"><?= e($note) ?></div><?php endif; ?>
  <?php if($errors): ?><div class="banner error"><?php foreach($errors as $m): ?><div><?= e($m) ?></div><?php endforeach; ?></div><?php endif; ?>

  <div class="tabs">
    <button class="tab-btn active" data-tab="new">🆕 New Requests</button>
    <button class="tab-btn" data-tab="repair">🔧 Repair Desk</button>
    <button class="tab-btn" data-tab="pickup">🚚 Pickup</button>
    <button class="tab-btn" data-tab="onsite">🛠️ Onsite</button>
    <button class="tab-btn" data-tab="history">📜 History</button>
  </div>

  <!-- New -->
  <div id="new" class="tab-content active">
    <input type="text" class="search-input" placeholder="🔎 Search..." data-target="list-new">
    <?php if(!$newReqs): ?><p>No new requests.</p><?php endif; ?>
    <div class="list" id="list-new">
      <?php foreach($newReqs as $r): ?>
        <article class="card searchable">
          <h3><?= e($r['ticket_code']) ?> — <?= e($r['device_type']) ?> <?= e($r['brand']) ?> <?= e($r['model']) ?></h3>
          <p><strong>Created:</strong> <?= e($r['created_at']) ?></p>
          <div class="details">
            <div><strong>Customer:</strong> <?= e($r['cust_name']) ?> • <?= e($r['cust_email']) ?> <?= $r['cust_phone']?'• '.e($r['cust_phone']):'' ?></div>
            <div><strong>Brand:</strong> <?= e($r['brand']) ?> | <strong>Model:</strong> <?= e($r['model']) ?> | <strong>Serial/IMEI:</strong> <?= e($r['serial_no']) ?></div>
            <div><strong>Service:</strong> <?= ucfirst($r['service_type']) ?> | <strong>Contact:</strong> <?= e($r['preferred_contact']) ?></div>
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
            <?php if($r['service_type']==='dropoff'): ?>
              <button class="btn primary" name="act" value="accept_dropoff">Forward to Repair</button>
            <?php elseif($r['service_type']==='pickup'): ?>
              <button class="btn primary" name="act" value="start_pickup">Start Pickup</button>
            <?php elseif($r['service_type']==='onsite'): ?>
              <button class="btn primary" name="act" value="start_onsite">Start Onsite</button>
            <?php endif; ?>
            <button class="btn outline" name="act" value="reject">Reject</button>
          </form>
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
          <h3><?= e($r['ticket_code']) ?> — <?= e($r['device_type']) ?> <?= e($r['brand']) ?> <?= e($r['model']) ?></h3>
          <p><strong>Status:</strong> <?= e($r['status']) ?> | <strong>Created:</strong> <?= e($r['created_at']) ?></p>
          <button type="button" class="btn subtle toggle-timeline">📜 View Timeline</button>
          <div class="timeline">
            <?php foreach(fetch_history($r['id']) as $h): ?>
              <div>[<?= e($h['changed_at']) ?>] <?= e($h['status']) ?> — <?= e($h['note']) ?> <?= $h['staff']?'by '.e($h['staff']):'' ?></div>
            <?php endforeach; ?>
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
            <h3><?= e($r['ticket_code']) ?> — <?= e($r['device_type']) ?> <?= e($r['brand']) ?> <?= e($r['model']) ?></h3>
            <p><strong>Status:</strong> <?= e($r['status']) ?> | <strong>Created:</strong> <?= e($r['created_at']) ?></p>
            <form method="post">
              <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
              <input type="hidden" name="rid" value="<?= e($r['id']) ?>">
              <button class="btn primary" name="act" value="pickup_received">Mark as Received</button>
            </form>
            <button type="button" class="btn subtle toggle-timeline">📜 View Timeline</button>
            <div class="timeline">
              <?php foreach(fetch_history($r['id']) as $h): ?>
                <div>[<?= e($h['changed_at']) ?>] <?= e($h['status']) ?> — <?= e($h['note']) ?> <?= $h['staff']?'by '.e($h['staff']):'' ?></div>
              <?php endforeach; ?>
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
            <h3><?= e($r['ticket_code']) ?> — <?= e($r['device_type']) ?> <?= e($r['brand']) ?> <?= e($r['model']) ?></h3>
            <p><strong>Status:</strong> <?= e($r['status']) ?> | <strong>Created:</strong> <?= e($r['created_at']) ?></p>
            <form method="post">
              <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
              <input type="hidden" name="rid" value="<?= e($r['id']) ?>">
              <button class="btn primary" name="act" value="at_warehouse">At Warehouse</button>
            </form>
            <button type="button" class="btn subtle toggle-timeline">📜 View Timeline</button>
            <div class="timeline">
              <?php foreach(fetch_history($r['id']) as $h): ?>
                <div>[<?= e($h['changed_at']) ?>] <?= e($h['status']) ?> — <?= e($h['note']) ?> <?= $h['staff']?'by '.e($h['staff']):'' ?></div>
              <?php endforeach; ?>
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
            <h3><?= e($r['ticket_code']) ?> — <?= e($r['device_type']) ?> <?= e($r['brand']) ?> <?= e($r['model']) ?></h3>
            <p><strong>Status:</strong> <?= e($r['status']) ?> | <strong>Created:</strong> <?= e($r['created_at']) ?></p>
            <form method="post">
              <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
              <input type="hidden" name="rid" value="<?= e($r['id']) ?>">
              <button class="btn primary" name="act" value="forward_repair">Forward to Repair</button>
            </form>
            <button type="button" class="btn subtle toggle-timeline">📜 View Timeline</button>
            <div class="timeline">
              <?php foreach(fetch_history($r['id']) as $h): ?>
                <div>[<?= e($h['changed_at']) ?>] <?= e($h['status']) ?> — <?= e($h['note']) ?> <?= $h['staff']?'by '.e($h['staff']):'' ?></div>
              <?php endforeach; ?>
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
            <h3><?= e($r['ticket_code']) ?> — <?= e($r['device_type']) ?> <?= e($r['brand']) ?> <?= e($r['model']) ?></h3>
            <p><strong>Status:</strong> <?= e($r['status']) ?> | <strong>Created:</strong> <?= e($r['created_at']) ?></p>
            <form method="post">
              <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
              <input type="hidden" name="rid" value="<?= e($r['id']) ?>">
              <button class="btn primary" name="act" value="onsite_repair">Repair Started</button>
            </form>
            <button type="button" class="btn subtle toggle-timeline">📜 View Timeline</button>
            <div class="timeline">
              <?php foreach(fetch_history($r['id']) as $h): ?>
                <div>[<?= e($h['changed_at']) ?>] <?= e($h['status']) ?> — <?= e($h['note']) ?> <?= $h['staff']?'by '.e($h['staff']):'' ?></div>
              <?php endforeach; ?>
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
            <h3><?= e($r['ticket_code']) ?> — <?= e($r['device_type']) ?> <?= e($r['brand']) ?> <?= e($r['model']) ?></h3>
            <p><strong>Status:</strong> <?= e($r['status']) ?> | <strong>Created:</strong> <?= e($r['created_at']) ?></p>
            <form method="post">
              <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
              <input type="hidden" name="rid" value="<?= e($r['id']) ?>">
              <button class="btn primary" name="act" value="onsite_done">Mark Completed</button>
            </form>
            <button type="button" class="btn subtle toggle-timeline">📜 View Timeline</button>
            <div class="timeline">
              <?php foreach(fetch_history($r['id']) as $h): ?>
                <div>[<?= e($h['changed_at']) ?>] <?= e($h['status']) ?> — <?= e($h['note']) ?> <?= $h['staff']?'by '.e($h['staff']):'' ?></div>
              <?php endforeach; ?>
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
              <?php foreach(fetch_history($r['id']) as $h): ?>
                <div>[<?= e($h['changed_at']) ?>] <?= e($h['status']) ?> — <?= e($h['note']) ?> <?= $h['staff']?'by '.e($h['staff']):'' ?></div>
              <?php endforeach; ?>
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
          <h3><?= e($r['ticket_code']) ?> — <?= e($r['device_type']) ?> <?= e($r['brand']) ?> <?= e($r['model']) ?></h3>
          <p><strong>Final Status:</strong> <?= e($r['status']) ?> | <strong>Created:</strong> <?= e($r['created_at']) ?></p>
          <button type="button" class="btn subtle toggle-timeline">📜 View Timeline</button>
          <div class="timeline">
            <?php foreach(fetch_history($r['id']) as $h): ?>
              <div>[<?= e($h['changed_at']) ?>] <?= e($h['status']) ?> — <?= e($h['note']) ?> <?= $h['staff']?'by '.e($h['staff']):'' ?></div>
            <?php endforeach; ?>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  </div>
</main>
<script>
// Top-level tabs
document.querySelectorAll('.tab-btn').forEach(btn=>{
  btn.addEventListener('click',()=>{
    document.querySelectorAll('.tab-btn').forEach(b=>b.classList.remove('active'));
    btn.classList.add('active');
    const id=btn.dataset.tab;
    document.querySelectorAll('.tab-content').forEach(tc=>tc.classList.remove('active'));
    document.getElementById(id).classList.add('active');
  });
});

// Subtabs (event delegation)
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

// 🔎 Live search across each list
document.querySelectorAll('.search-input').forEach(input=>{
  input.addEventListener('input',()=>{
    let term = input.value.toLowerCase();
    let target = document.getElementById(input.dataset.target);
    if(!target) return;
    target.querySelectorAll('.searchable').forEach(card=>{
      let txt = card.innerText.toLowerCase();
      card.style.display = txt.includes(term) ? '' : 'none';
    });
  });
});
</script>
<script src="../../assets/js/app.js"></script>
</body>
</html>
