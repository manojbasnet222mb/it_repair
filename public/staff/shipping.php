<?php
// /htdocs/it_repair/public/staff/shipping.php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/guard.php';

require_role('staff','admin');

$errors = [];
$messages = [];

function shipments_index_rows(): array {
  $sql = "SELECT s.*, r.ticket_code, r.device_type, r.brand, r.model,
                 u.name AS customer_name, u.email AS customer_email
          FROM shipments s
          JOIN repair_requests r ON r.id = s.request_id
          JOIN users u ON u.id = r.customer_id
          ORDER BY s.created_at DESC";
  $st = db()->query($sql);
  return $st->fetchAll();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_verify($_POST['csrf'] ?? '')) {
    $errors['csrf'] = 'Invalid session token. Please refresh and try again.';
  } else {
    $act = $_POST['act'] ?? '';
    $sid = isset($_POST['shipment_id']) ? (int)$_POST['shipment_id'] : 0;
    $rid = isset($_POST['request_id']) ? (int)$_POST['request_id'] : 0;

    if ($act === 'create') {
      $carrier = trim($_POST['carrier'] ?? '');
      $tracking_no = trim($_POST['tracking_no'] ?? '');
      if (!$rid) $errors['request_id'] = 'Missing repair request.';
      if (!$carrier) $errors['carrier'] = 'Carrier is required.';
      if (!$errors) {
        $ins = db()->prepare("INSERT INTO shipments (request_id, carrier, tracking_no, status, created_by) VALUES (?, ?, ?, 'Ready', ?)");
        $ins->execute([$rid, $carrier, $tracking_no ?: null, $_SESSION['user']['id']]);
        $messages[] = 'Shipment created.';
      }
    }

    if ($act === 'mark_shipped') {
      if (!$sid) $errors['shipment_id'] = 'Missing shipment.';
      if (!$errors) {
        db()->beginTransaction();
        try {
          // Update shipment row using the correct column name: `status`
          $up1 = db()->prepare("UPDATE shipments SET status='Shipped', shipped_at=NOW() WHERE id=? AND status!='Shipped'");
          $up1->execute([$sid]);

          // Locate related request
          $rq = db()->prepare("SELECT request_id FROM shipments WHERE id=?");
          $rq->execute([$sid]);
          $req = $rq->fetch();
          if ($req) {
            $rid2 = (int)$req['request_id'];
            // Transition the repair request status to 'Shipped'
            $up2 = db()->prepare("UPDATE repair_requests SET status='Shipped', updated_at=NOW() WHERE id=?");
            $up2->execute([$rid2]);

            // History
            $h = db()->prepare("INSERT INTO request_status_history (request_id, status, changed_by) VALUES (?,?,?)");
            $h->execute([$rid2, 'Shipped', $_SESSION['user']['id']]);
          }
          db()->commit();
          $messages[] = 'Marked as Shipped.';
        } catch (Throwable $e) {
          db()->rollBack();
          $errors['db'] = 'Failed to mark shipped: ' . $e->getMessage();
        }
      }
    }

    if ($act === 'mark_delivered') {
      if (!$sid) $errors['shipment_id'] = 'Missing shipment.';
      if (!$errors) {
        db()->beginTransaction();
        try {
          $up1 = db()->prepare("UPDATE shipments SET status='Delivered', delivered_at=NOW() WHERE id=? AND status!='Delivered'");
          $up1->execute([$sid]);

          $rq = db()->prepare("SELECT request_id FROM shipments WHERE id=?");
          $rq->execute([$sid]);
          $req = $rq->fetch();
          if ($req) {
            $rid2 = (int)$req['request_id'];
            $up2 = db()->prepare("UPDATE repair_requests SET status='Delivered', updated_at=NOW() WHERE id=?");
            $up2->execute([$rid2]);
            $h = db()->prepare("INSERT INTO request_status_history (request_id, status, changed_by) VALUES (?,?,?)");
            $h->execute([$rid2, 'Delivered', $_SESSION['user']['id']]);
          }
          db()->commit();
          $messages[] = 'Marked as Delivered.';
        } catch (Throwable $e) {
          db()->rollBack();
          $errors['db'] = 'Failed to mark delivered: ' . $e->getMessage();
        }
      }
    }
  }
}

$rows = shipments_index_rows();

include __DIR__ . '/../../includes/header.php';
?>
  <h2>Shipping Desk</h2>

  <?php if ($messages): ?>
    <div class="card trust"><?php foreach($messages as $m): ?><div><?= e($m) ?></div><?php endforeach; ?></div>
  <?php endif; ?>

  <?php if ($errors): ?>
    <div class="card danger"><?php foreach($errors as $m): ?><div><?= e($m) ?></div><?php endforeach; ?></div>
  <?php endif; ?>

  <details class="card" open>
    <summary>Create Shipment</summary>
    <form method="post" class="mini-form" style="margin-top:8px;display:grid;gap:8px;grid-template-columns:2fr 2fr 2fr 1fr;">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="act" value="create">
      <input type="number" name="request_id" placeholder="Repair Request ID" required>
      <input name="carrier" placeholder="Carrier (e.g., DHL, FedEx)" required>
      <input name="tracking_no" placeholder="Tracking No (optional)">
      <button type="submit">Create</button>
    </form>
  </details>

  <div class="cards">
    <?php foreach($rows as $r): ?>
      <article class="card">
        <header style="display:flex;justify-content:space-between;align-items:center;">
          <h3>Ticket <?= e($r['ticket_code']) ?> — <?= e($r['device_type']) ?> <?= $r['brand']?'· '.e($r['brand']):'' ?> <?= $r['model']?'· '.e($r['model']):'' ?></h3>
          <div class="subtitle">Customer: <?= e($r['customer_name']) ?> • Status: <?= e($r['status']) ?></div>
        </header>

        <p style="margin-top:6px;">Carrier: <?= e($r['carrier'] ?? '—') ?> • Tracking: <?= e($r['tracking_no'] ?? '—') ?> •
          Created: <?= e($r['created_at']) ?> <?php if($r['shipped_at']): ?> • Shipped: <?= e($r['shipped_at']) ?><?php endif; ?>
          <?php if($r['delivered_at']): ?> • Delivered: <?= e($r['delivered_at']) ?><?php endif; ?>
        </p>

        <div style="display:flex; gap:8px; margin-top:8px;">
          <?php if ($r['status'] === 'Ready'): ?>
            <form method="post">
              <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
              <input type="hidden" name="act" value="mark_shipped">
              <input type="hidden" name="shipment_id" value="<?= (int)$r['id'] ?>">
              <button type="submit">Mark Shipped</button>
            </form>
          <?php endif; ?>

          <?php if ($r['status'] === 'Shipped'): ?>
            <form method="post">
              <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
              <input type="hidden" name="act" value="mark_delivered">
              <input type="hidden" name="shipment_id" value="<?= (int)$r['id'] ?>">
              <button type="submit">Mark Delivered</button>
            </form>
          <?php endif; ?>
        </div>
      </article>
    <?php endforeach; ?>
  </div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
