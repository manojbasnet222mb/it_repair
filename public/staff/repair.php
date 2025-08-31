<?php
require_once __DIR__.'/../../includes/bootstrap.php';
require_role('staff','admin');

$note = null;
$errors = [];

// --- Helpers ---------------------------------------------------------------
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
    $errors['csrf'] = 'Invalid token';
  } else {
    $act = $_POST['act'] ?? '';
    $rid = (int)($_POST['rid'] ?? 0);

    // Load request once
    $reqStmt = db()->prepare("SELECT * FROM repair_requests WHERE id=?");
    $reqStmt->execute([$rid]);
    $req = $reqStmt->fetch();
    if(!$req){
      $errors['req'] = 'Request not found';
    }

    if(!$errors){
      $userId = $_SESSION['user']['id'];
      $isInRepair = ($req['status'] === 'In Repair');

      // Add Part
      if ($act === 'addpart') {
        if(!$isInRepair){
          $errors['status'] = 'Cannot modify parts once not In Repair.';
        } else {
          $item = trim($_POST['item'] ?? '');
          $unit = trim($_POST['unit'] ?? 'pcs');
          $qty  = (float)($_POST['qty'] ?? 1);
          $remarks = trim($_POST['remarks'] ?? '');
          if (!$item) $errors['item'] = 'Item required';
          if ($qty <= 0) $errors['qty'] = 'Qty must be > 0';
          if (!$errors) {
            $s = db()->prepare("INSERT INTO request_parts (request_id,item,unit,qty,remarks,added_by) VALUES (?,?,?,?,?,?)");
            $s->execute([$rid,$item,$unit,$qty,$remarks,$userId]);
            $note = 'Part added.';
          }
        }
      }

      // Remove Part
      if (!$errors && $act === 'delpart') {
        if(!$isInRepair){
          $errors['status'] = 'Cannot remove parts once not In Repair.';
        } else {
          $pid = (int)($_POST['pid'] ?? 0);
          if ($pid) {
            $s = db()->prepare("DELETE FROM request_parts WHERE id=? AND request_id=?");
            $s->execute([$pid,$rid]);
            $note = 'Part removed.';
          }
        }
      }

      // Update device details (quick edit)
      if (!$errors && $act === 'update_device') {
        $brand = trim($_POST['brand'] ?? '');
        $model = trim($_POST['model'] ?? '');
        $serial= trim($_POST['serial_no'] ?? '');
        if(!$brand || !$model || !$serial){
          $errors['device'] = 'Brand, Model and Serial/IMEI are required.';
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
          $errors['note_text'] = 'Note cannot be empty';
        } else {
          $cur = $req['status'];
          add_history($rid,$cur,'Technician note: '.$n,$userId);
          $note = 'Note added to timeline.';
        }
      }

      // Generate Quote
      if (!$errors && $act === 'generate_quote') {
        if(!$isInRepair){
          $errors['status'] = 'Can only generate quote while In Repair.';
        } else {
          $pdo = db();
          $pdo->beginTransaction();
          try{
            $invoice = ensure_invoice($rid,$userId);
            $s = $pdo->prepare("UPDATE invoices SET status='Draft', quote_status='Pending' WHERE id=?");
            $s->execute([$invoice['id']]);
            add_history($rid,'In Repair','Quote generated (Pending approval)',$userId);
            $pdo->commit();
            $note = 'Quote generated (Pending approval).';
          } catch(Exception $e){
            $pdo->rollBack();
            $errors['quote'] = 'Failed to generate quote.';
            error_log($e->getMessage());
          }
        }
      }

      // Approve Quote
      if (!$errors && $act === 'approve_quote') {
        $iid = (int)($_POST['invoice_id'] ?? 0);
        if(!$iid){
          $errors['invoice'] = 'Missing invoice id';
        } else {
          $inv = db()->prepare("SELECT * FROM invoices WHERE id=? AND request_id=?");
          $inv->execute([$iid,$rid]);
          $invoice = $inv->fetch();
          if(!$invoice){
            $errors['invoice'] = 'Invoice not found';
          } elseif($invoice['quote_status'] !== 'Pending'){
            $errors['invoice'] = 'Only pending quotes can be approved';
          } else {
            db()->prepare("UPDATE invoices SET quote_status='Approved' WHERE id=?")->execute([$iid]);
            add_history($rid,'In Repair','Quote approved',$userId);
            $note = 'Quote approved.';
          }
        }
      }

      // Reject Quote
      if (!$errors && $act === 'reject_quote') {
        $iid = (int)($_POST['invoice_id'] ?? 0);
        $reason = trim($_POST['reject_reason'] ?? '');
        $inv = db()->prepare("SELECT * FROM invoices WHERE id=? AND request_id=?");
        $inv->execute([$iid,$rid]);
        $invoice = $inv->fetch();
        if(!$invoice){
          $errors['invoice'] = 'Invoice not found';
        } else {
          db()->prepare("UPDATE invoices SET quote_status='Rejected' WHERE id=?")->execute([$iid]);
          add_history($rid,'In Repair','Quote rejected'.($reason?': '.$reason:''),$userId);
          $note = 'Quote rejected.';
        }
      }

      // Send to Billing
      if (!$errors && $act === 'complete') {
        $invoice = get_invoice($rid);
        if ($invoice && $invoice['quote_status'] === 'Approved' && $isInRepair) {
          $pdo = db();
          $pdo->beginTransaction();
          try{
            $pdo->prepare("UPDATE repair_requests SET status='Billed' WHERE id=?")->execute([$rid]);
            add_history($rid,'Billed','Forwarded to Billing',$userId);
            $pdo->commit();
            $note = 'Forwarded to Billing.';
          } catch(Exception $e){
            $pdo->rollBack();
            $errors['complete'] = 'Failed to forward to Billing.';
            error_log($e->getMessage());
          }
        } else {
          $errors['complete'] = 'Cannot complete: Quote not approved or wrong status.';
        }
      }

      // Mark Delivered
      if (!$errors && $act === 'mark_delivered') {
        if($req['status'] !== 'Billed'){
          $errors['deliver'] = 'Only billed requests can be delivered.';
        } else {
          $pdo = db();
          $pdo->beginTransaction();
          try{
            $pdo->prepare("UPDATE repair_requests SET status='Delivered' WHERE id=?")->execute([$rid]);
            add_history($rid,'Delivered','Device delivered to customer',$userId);
            $pdo->commit();
            $note = 'Marked as Delivered.';
          } catch(Exception $e){
            $pdo->rollBack();
            $errors['deliver'] = 'Failed to mark delivered.';
          }
        }
      }
    }
  }
}

// --- Queries for view -------------------------------------------------------
$rows = db()->query("SELECT rr.*, u.name cust_name, u.email cust_email, u.phone cust_phone
  FROM repair_requests rr
  JOIN users u ON u.id=rr.customer_id
  WHERE rr.status IN ('In Repair','Billed')
  ORDER BY rr.id DESC")->fetchAll();

?>
<!doctype html>
<html>
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Repair Desk</title>

<!-- Tailwind CDN for professional UI -->
<script src="https://cdn.tailwindcss.com"></script>

<!-- Keep your base styles if needed -->
<link rel="stylesheet" href="../../assets/css/styles.css">

<style>
  /* Utility bridges to your existing variables if present */
  .chip{ @apply inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium; }
  .status-inrepair{ @apply bg-blue-100 text-blue-800; }
  .status-billed{ @apply bg-amber-100 text-amber-800; }
  .status-delivered{ @apply bg-emerald-100 text-emerald-800; }
  .card{ @apply bg-white rounded-xl shadow-sm border border-gray-200; }
  .card-header{ @apply flex items-start justify-between gap-4; }
  .muted{ @apply text-gray-500; }
  .btn{ @apply inline-flex items-center justify-center rounded-lg px-3 py-2 text-sm font-medium; }
  .btn-primary{ @apply bg-indigo-600 text-white hover:bg-indigo-700; }
  .btn-outline{ @apply border border-gray-300 text-gray-700 hover:bg-gray-50; }
  .btn-success{ @apply bg-emerald-600 text-white hover:bg-emerald-700; }
  .btn-danger{ @apply bg-rose-600 text-white hover:bg-rose-700; }
  .btn-subtle{ @apply bg-gray-100 text-gray-800 hover:bg-gray-200; }
  details>summary::-webkit-details-marker{ display:none; }
  details>summary{ @apply cursor-pointer select-none; }
  .feed-dot{ @apply w-2 h-2 rounded-full bg-gray-300 mt-2; }
  .feed-line{ @apply absolute left-[11px] top-6 bottom-0 w-[2px] bg-gray-200; }
</style>
</head>

<body class="bg-gray-50">
<?php require __DIR__.'/../../includes/header.php'; ?>

<main class="max-w-6xl mx-auto px-4 py-6">
  <div class="mb-6">
    <h1 class="text-2xl font-semibold text-gray-900">Repair Desk</h1>
    <?php if($note): ?><div class="mt-3 rounded-lg bg-emerald-50 text-emerald-700 px-4 py-2 border border-emerald-200"><?= e($note) ?></div><?php endif; ?>
    <?php if($errors): ?>
      <div class="mt-3 rounded-lg bg-rose-50 text-rose-700 px-4 py-2 border border-rose-200">
        <?php foreach($errors as $m): ?><div><?= e($m) ?></div><?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <!-- Tabs -->
  <div class="flex flex-wrap gap-2 mb-4">
    <button class="tab-btn btn btn-subtle data-[active=true]:bg-indigo-600 data-[active=true]:text-white" data-tab="inrepair" data-active="true">🔧 In Repair</button>
    <button class="tab-btn btn btn-subtle" data-tab="billed">💸 Ready for Billing / Billed</button>
  </div>

  <!-- In Repair -->
  <section id="inrepair" class="tab-content block">
    <input type="text" class="search-input w-full rounded-lg border border-gray-300 px-3 py-2 mb-4" placeholder="🔎 Search (ticket, device, customer, parts, notes)" data-target="list-inrepair">
    <div id="list-inrepair" class="grid gap-4">
      <?php foreach($rows as $r): if($r['status']!== 'In Repair') continue;
        $plist   = parts_for($r['id']);
        $invoice = get_invoice($r['id']);
        $history = history_for($r['id']);
        $problem = $r['problem'] ?? ($r['problem_reported'] ?? ($r['issue'] ?? ($r['notes'] ?? '')));
      ?>
      <article class="card searchable p-5" data-rid="<?= e($r['id']) ?>">
        <!-- Header -->
        <div class="card-header">
          <div>
            <div class="flex flex-wrap items-center gap-2">
              <h2 class="text-lg font-semibold text-gray-900"><?= e($r['ticket_code']) ?></h2>
              <?php
                $status = $r['status'];
                $statusClass = 'status-inrepair';
                if($status==='Billed') $statusClass='status-billed';
                if($status==='Delivered') $statusClass='status-delivered';
              ?>
              <span class="chip <?= $statusClass ?>"><?= e($status) ?></span>
              <?php if($invoice): ?>
                <span class="chip bg-gray-100 text-gray-800">Invoice #<?= e($invoice['id']) ?> — <?= e($invoice['quote_status']) ?></span>
              <?php endif; ?>
            </div>
            <div class="mt-1 text-sm text-gray-600">
              <?= e($r['device_type']) ?><?= $r['brand']?' · '.e($r['brand']):'' ?><?= $r['model']?' · '.e($r['model']):'' ?>
            </div>
            <div class="mt-1 text-sm muted">
              Customer: <span class="font-medium text-gray-800"><?= e($r['cust_name']) ?></span> • <?= e($r['cust_email']) ?><?= $r['cust_phone']?' • '.e($r['cust_phone']):'' ?>
            </div>
          </div>

          <!-- Action Toolbar -->
          <div class="flex flex-col sm:flex-row gap-2 items-start sm:items-center">
            <form method="post">
              <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
              <input type="hidden" name="rid" value="<?= e($r['id']) ?>">
              <button class="btn btn-primary" name="act" value="generate_quote">Generate Quote</button>
            </form>

            <?php if($invoice && $invoice['quote_status'] === 'Pending'): ?>
              <form method="post">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="rid" value="<?= e($r['id']) ?>">
                <input type="hidden" name="invoice_id" value="<?= e($invoice['id']) ?>">
                <button class="btn btn-success" name="act" value="approve_quote">Approve Quote</button>
              </form>
              <form method="post" onsubmit="return confirm('Reject this quote?');" class="flex gap-2">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="rid" value="<?= e($r['id']) ?>">
                <input type="hidden" name="invoice_id" value="<?= e($invoice['id']) ?>">
                <input name="reject_reason" class="rounded-lg border border-gray-300 px-3 py-2 text-sm" placeholder="Reason (optional)">
                <button class="btn btn-outline" name="act" value="reject_quote">Reject</button>
              </form>
            <?php endif; ?>

            <?php if($invoice && $invoice['quote_status'] === 'Approved'): ?>
              <form method="post" onsubmit="return confirm('Send to Billing?');">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="rid" value="<?= e($r['id']) ?>">
                <button class="btn btn-primary" name="act" value="complete">Send to Billing</button>
              </form>
            <?php endif; ?>
          </div>
        </div>

        <div class="mt-5 grid grid-cols-1 lg:grid-cols-3 gap-5">
          <!-- Left column -->
          <div class="space-y-5 lg:col-span-2">

            <!-- Problem Reported -->
            <div class="rounded-xl border border-amber-200 bg-amber-50 p-4">
              <div class="text-xs font-semibold uppercase tracking-wide text-amber-700">Problem Reported</div>
              <div class="mt-1 text-sm text-amber-900 whitespace-pre-line"><?= $problem ? e($problem) : '<span class="opacity-60">No problem described.</span>' ?></div>
            </div>

            <!-- Device Details -->
            <div class="card p-4">
              <div class="flex items-center justify-between">
                <h3 class="font-semibold text-gray-900">Device Details</h3>
                <button class="btn btn-outline toggle-edit" data-target="#devform-<?= e($r['id']) ?>">Edit</button>
              </div>

              <!-- Read mode -->
              <div class="mt-4 grid grid-cols-1 sm:grid-cols-2 gap-x-8 gap-y-3">
                <div>
                  <div class="text-xs uppercase muted">Brand</div>
                  <div class="text-gray-900 font-medium"><?= e($r['brand']) ?: '—' ?></div>
                </div>
                <div>
                  <div class="text-xs uppercase muted">Model</div>
                  <div class="text-gray-900 font-medium"><?= e($r['model']) ?: '—' ?></div>
                </div>
                <div>
                  <div class="text-xs uppercase muted">Serial / IMEI</div>
                  <div class="text-gray-900 font-medium"><?= e($r['serial_no']) ?: '—' ?></div>
                </div>
                <div>
                  <div class="text-xs uppercase muted">Device Type</div>
                  <div class="text-gray-900 font-medium"><?= e($r['device_type']) ?: '—' ?></div>
                </div>
              </div>

              <!-- Edit form (hidden until toggled) -->
              <form method="post" id="devform-<?= e($r['id']) ?>" class="hidden mt-4 grid grid-cols-1 sm:grid-cols-2 gap-4">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="rid" value="<?= e($r['id']) ?>">

                <div>
                  <label class="block text-sm font-medium text-gray-700">Brand</label>
                  <input name="brand" class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2" placeholder="Brand" value="<?= e($r['brand']) ?>">
                </div>
                <div>
                  <label class="block text-sm font-medium text-gray-700">Model</label>
                  <input name="model" class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2" placeholder="Model" value="<?= e($r['model']) ?>">
                </div>
                <div>
                  <label class="block text-sm font-medium text-gray-700">Serial / IMEI</label>
                  <input name="serial_no" class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2" placeholder="Serial/IMEI" value="<?= e($r['serial_no']) ?>">
                </div>
                <div>
                  <label class="block text-sm font-medium text-gray-700">Device Type</label>
                  <input class="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 bg-gray-50" value="<?= e($r['device_type']) ?>" disabled>
                </div>
                <div class="sm:col-span-2 flex gap-2">
                  <button class="btn btn-primary" name="act" value="update_device">Save Device</button>
                  <button type="button" class="btn btn-subtle toggle-edit" data-target="#devform-<?= e($r['id']) ?>">Cancel</button>
                </div>
              </form>
            </div>

            <!-- Parts Used -->
            <div class="card p-4">
              <h3 class="font-semibold text-gray-900">Parts Used</h3>

              <div class="mt-3 overflow-x-auto">
                <table class="min-w-full text-sm">
                  <thead>
                    <tr class="text-left text-gray-600">
                      <th class="py-2 pr-4">Item</th>
                      <th class="py-2 pr-4">Qty</th>
                      <th class="py-2 pr-4">Unit</th>
                      <th class="py-2 pr-4">Remarks</th>
                      <th class="py-2 text-right">Action</th>
                    </tr>
                  </thead>
                  <tbody class="divide-y divide-gray-100">
                    <?php foreach($plist as $p): ?>
                      <tr>
                        <td class="py-2 pr-4"><?= e($p['item']) ?></td>
                        <td class="py-2 pr-4"><?= e($p['qty']) ?></td>
                        <td class="py-2 pr-4 muted"><?= e($p['unit']) ?></td>
                        <td class="py-2 pr-4 muted"><?= e($p['remarks']) ?></td>
                        <td class="py-2 text-right">
                          <form method="post" class="inline">
                            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="rid" value="<?= e($r['id']) ?>">
                            <input type="hidden" name="pid" value="<?= e($p['id']) ?>">
                            <button class="btn btn-outline" name="act" value="delpart" onclick="return confirm('Remove this part?')">Remove</button>
                          </form>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                    <?php if(!$plist): ?>
                      <tr><td colspan="5" class="py-3 text-center muted">No parts added yet.</td></tr>
                    <?php endif; ?>
                  </tbody>
                </table>
              </div>

              <!-- Add part -->
              <form method="post" class="mt-4 grid grid-cols-1 sm:grid-cols-5 gap-3">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="rid" value="<?= e($r['id']) ?>">

                <div class="sm:col-span-2">
                  <label class="block text-sm font-medium text-gray-700">Item</label>
                  <input name="item" class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2" placeholder="e.g., SSD 512GB">
                </div>
                <div>
                  <label class="block text-sm font-medium text-gray-700">Unit</label>
                  <input name="unit" class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2" placeholder="pcs" value="pcs">
                </div>
                <div>
                  <label class="block text-sm font-medium text-gray-700">Qty</label>
                  <input name="qty" type="number" step="0.01" class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2" value="1">
                </div>
                <div>
                  <label class="block text-sm font-medium text-gray-700">Remarks</label>
                  <input name="remarks" class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2" placeholder="Optional notes">
                </div>
                <div class="sm:col-span-5">
                  <button class="btn btn-outline" name="act" value="addpart">Add Part</button>
                </div>
              </form>
            </div>

            <!-- Technician Notes -->
            <div class="card p-4">
              <h3 class="font-semibold text-gray-900">Technician Notes</h3>
              <form method="post" class="mt-3">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="rid" value="<?= e($r['id']) ?>">
                <textarea class="w-full min-h-[110px] rounded-lg border border-gray-300 px-3 py-2" name="note_text" placeholder="Diagnostics, work performed, advice..."></textarea>
                <div class="mt-3">
                  <button class="btn btn-subtle" name="act" value="add_note">➕ Add Note</button>
                </div>
              </form>
            </div>
          </div>

          <!-- Right column -->
          <div class="space-y-5">
            <!-- Timeline -->
            <div class="card p-4">
              <h3 class="font-semibold text-gray-900">Status & Notes Timeline</h3>
              <div class="relative mt-3">
                <div class="feed-line"></div>
                <ul class="space-y-4">
                  <?php if($history): foreach($history as $h): ?>
                  <li class="relative pl-6">
                    <span class="feed-dot absolute left-0"></span>
                    <div class="text-sm">
                      <div class="flex items-center justify-between">
                        <div>
                          <span class="font-medium text-gray-900"><?= e($h['status']) ?></span>
                          <?php if($h['note']): ?>
                            <span class="text-gray-700">— <?= e($h['note']) ?></span>
                          <?php endif; ?>
                        </div>
                        <div class="text-xs muted"><?= e($h['created_at'] ?? '') ?></div>
                      </div>
                      <div class="text-xs muted mt-0.5"><?= e($h['user_name'] ?: 'System') ?></div>
                    </div>
                  </li>
                  <?php endforeach; else: ?>
                    <li class="relative pl-6">
                      <span class="feed-dot absolute left-0"></span>
                      <div class="text-sm muted">No timeline yet.</div>
                    </li>
                  <?php endif; ?>
                </ul>
              </div>
            </div>
          </div>
        </div>
      </article>
      <?php endforeach; ?>
      <?php if(!array_filter($rows, fn($x)=>$x['status']==='In Repair')): ?>
        <p class="muted">Nothing in repair right now.</p>
      <?php endif; ?>
    </div>
  </section>

  <!-- Billed -->
  <section id="billed" class="tab-content hidden">
    <input type="text" class="search-input w-full rounded-lg border border-gray-300 px-3 py-2 mb-4" placeholder="🔎 Search..." data-target="list-billed">
    <div id="list-billed" class="grid gap-4">
      <?php foreach($rows as $r): if($r['status']!== 'Billed') continue; $invoice = get_invoice($r['id']); ?>
      <article class="card p-5 searchable">
        <div class="flex items-start justify-between">
          <div>
            <div class="flex items-center gap-2">
              <h3 class="text-lg font-semibold text-gray-900"><?= e($r['ticket_code']) ?></h3>
              <span class="chip status-billed">Billed</span>
              <?php if($invoice): ?><span class="chip bg-gray-100 text-gray-800">Invoice #<?= e($invoice['id']) ?> — <?= e($invoice['quote_status']) ?></span><?php endif; ?>
            </div>
            <div class="mt-1 text-sm text-gray-600">
              <?= e($r['device_type']) ?><?= $r['brand']?' · '.e($r['brand']):'' ?><?= $r['model']?' · '.e($r['model']):'' ?>
            </div>
            <div class="mt-1 text-sm muted">
              Customer: <span class="font-medium text-gray-800"><?= e($r['cust_name']) ?></span> • <?= e($r['cust_email']) ?><?= $r['cust_phone']?' • '.e($r['cust_phone']):'' ?>
            </div>
          </div>
          <form method="post" onsubmit="return confirm('Mark as delivered to customer?');">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="rid" value="<?= e($r['id']) ?>">
            <button class="btn btn-success" name="act" value="mark_delivered">Mark Delivered</button>
          </form>
        </div>
      </article>
      <?php endforeach; ?>
      <?php if(!array_filter($rows, fn($x)=>$x['status']==='Billed')): ?><p class="muted">No billed items yet.</p><?php endif; ?>
    </div>
  </section>
</main>

<script>
// Tabs
const tabBtns = document.querySelectorAll('.tab-btn');
const tabSections = document.querySelectorAll('.tab-content');
tabBtns.forEach(btn=>{
  btn.addEventListener('click', ()=>{
    const id = btn.dataset.tab;
    tabBtns.forEach(b=>b.dataset.active="false");
    btn.dataset.active="true";
    tabSections.forEach(sec=>sec.classList.add('hidden'));
    document.getElementById(id).classList.remove('hidden');
  });
});

// Live search per list
document.querySelectorAll('.search-input').forEach(input=>{
  input.addEventListener('input',()=>{
    const term = input.value.toLowerCase();
    const list = document.getElementById(input.dataset.target);
    if(!list) return;
    list.querySelectorAll('.searchable').forEach(card=>{
      const txt = card.innerText.toLowerCase();
      card.style.display = txt.includes(term) ? '' : 'none';
    });
  });
});

// Toggle edit forms for device
document.querySelectorAll('.toggle-edit').forEach(btn=>{
  btn.addEventListener('click', ()=>{
    const sel = btn.getAttribute('data-target');
    const el = document.querySelector(sel);
    if(!el) return;
    el.classList.toggle('hidden');
  });
});
</script>
</body>
</html>
