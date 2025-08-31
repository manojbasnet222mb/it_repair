<?php
// /htdocs/it_repair/public/staff/billing.php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_role('staff','admin');

$note = null; $errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_verify($_POST['csrf'] ?? '')) $errors['csrf'] = 'Invalid token';
  $act = $_POST['act'] ?? '';
  $rid = (int)($_POST['rid'] ?? 0);

  // Add invoice item
  if (!$errors && $act === 'additem') {
    $invoice_id = (int)($_POST['invoice_id'] ?? 0);
    $item = trim($_POST['item'] ?? '');
    $unit = trim($_POST['unit'] ?? 'pcs');
    $qty = (float)($_POST['qty'] ?? 1);
    $unit_price = (float)($_POST['unit_price'] ?? 0);

    if (!$invoice_id || $item === '' || $qty <= 0) {
      $errors['form'] = 'Please provide item, qty > 0 and a valid invoice.';
    } else {
      $st = db()->prepare("INSERT INTO invoice_items (invoice_id,item,unit,qty,unit_price,subtotal) VALUES (?,?,?,?,?,?)");
      $st->execute([$invoice_id,$item,$unit,$qty,$unit_price,$qty*$unit_price]);

      // Recompute totals and tax
      $sum = db()->prepare("SELECT COALESCE(SUM(subtotal),0) AS s FROM invoice_items WHERE invoice_id=?");
      $sum->execute([$invoice_id]);
      $subtotal = (float)$sum->fetchColumn();

      $inv = db()->prepare("SELECT tax_rate FROM invoices WHERE id=?");
      $inv->execute([$invoice_id]);
      $tax_rate = (float)$inv->fetchColumn();

      $tax_amount = round($subtotal * $tax_rate, 2);
      $total = $subtotal + $tax_amount;

      $up = db()->prepare("UPDATE invoices SET subtotal=?, tax_amount=?, total=? WHERE id=?");
      $up->execute([$subtotal, $tax_amount, $total, $invoice_id]);

      $note = 'Item added and totals updated.';
    }
  }

  // Finalize invoice (no more edits) and keep request in Billed status
  if (!$errors && $act === 'finalize') {
    $invoice_id = (int)($_POST['invoice_id'] ?? 0);
    if ($invoice_id) {
      db()->prepare("UPDATE invoices SET status='Finalized' WHERE id=?")->execute([$invoice_id]);
      $note = 'Invoice finalized. Ready for Shipping.';
    }
  }
}

// List requests that are in Billed stage
$rows = db()->query("SELECT rr.*, u.name AS cust_name
  FROM repair_requests rr
  JOIN users u ON u.id = rr.customer_id
  WHERE rr.status = 'Billed'
  ORDER BY rr.id DESC")->fetchAll();

function invoice_for($rid){
  $st = db()->prepare("SELECT * FROM invoices WHERE request_id=?");
  $st->execute([$rid]);
  return $st->fetch();
}

function invoice_items($invoice_id){
  $st = db()->prepare("SELECT * FROM invoice_items WHERE invoice_id=? ORDER BY id");
  $st->execute([$invoice_id]);
  return $st->fetchAll();
}

$title = "Billing Desk";
$subtitle = "Add items, update totals, and finalize invoices.";
include __DIR__ . '/../../includes/header.php';
?>
<main class="container">
  <?php if($note): ?><div class="alert success"><?= e($note) ?></div><?php endif; ?>
  <?php if($errors): ?>
    <div class="alert error">
      <?php foreach($errors as $e): ?><div><?= e($e) ?></div><?php endforeach; ?>
    </div>
  <?php endif; ?>

  <div class="cards">
    <?php foreach ($rows as $r): ?>
      <?php $inv = invoice_for($r['id']); ?>
      <article class="card">
        <header class="card-head">
          <h3>Ticket <?= e($r['ticket_code']) ?> — <?= e($r['device_type'].' '.$r['brand'].' '.$r['model']) ?></h3>
          <div class="muted">Customer: <?= e($r['cust_name']) ?></div>
        </header>

        <?php if($inv): ?>
          <section>
            <h4>Invoice #<?= e($inv['id']) ?> (<?= e($inv['status']) ?>)</h4>
            <div class="stack">
              <form method="post" class="row gap">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="rid" value="<?= (int)$r['id'] ?>">
                <input type="hidden" name="invoice_id" value="<?= (int)$inv['id'] ?>">
                <input name="item" placeholder="Item" required>
                <input name="unit" placeholder="Unit" value="pcs">
                <input name="qty" type="number" step="0.01" value="1" min="0.01" required>
                <input name="unit_price" type="number" step="0.01" value="0" min="0" required>
                <button class="btn" name="act" value="additem">Add Item</button>
              </form>

              <div>
                <?php $items = invoice_items($inv['id']); ?>
                <?php if($items): ?>
                  <ul class="list">
                    <?php foreach($items as $it): ?>
                      <li><?= e($it['qty'].' '.$it['unit'].' × '.$it['item'].' — '.number_format($it['subtotal'],2)) ?></li>
                    <?php endforeach; ?>
                  </ul>
                <?php else: ?>
                  <em>No items added yet.</em>
                <?php endif; ?>
              </div>

              <div class="muted">
                <strong>Subtotal:</strong> <?= e(number_format($inv['subtotal'],2)) ?> •
                <strong>Tax:</strong> <?= e(number_format($inv['tax_amount'],2)) ?> •
                <strong>Total:</strong> <?= e(number_format($inv['total'],2)) ?>
              </div>

              <form method="post">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="invoice_id" value="<?= (int)$inv['id'] ?>">
                <button class="btn primary" name="act" value="finalize">Finalize Invoice</button>
              </form>
            </div>
          </section>
        <?php else: ?>
          <em>Invoice missing — return to Repair desk to complete.</em>
        <?php endif; ?>
      </article>
    <?php endforeach; ?>
    <?php if(!$rows): ?><article class="card"><em>No items pending billing.</em></article><?php endif; ?>
  </div>
</main>
<script src="../../assets/js/app.js"></script>
</body></html>
