<?php
require_once __DIR__.'/../../includes/bootstrap.php';
$invoice_id = (int)($_GET['invoice_id'] ?? 0);

if ($invoice_id) {
  $up = db()->prepare("UPDATE invoices SET payment_status='Failed' WHERE id=?");
  $up->execute([$invoice_id]);
}
?>
<h2>Payment Failed ❌</h2>
<p>Something went wrong with your payment. Please try again.</p>
<a href="requests.php">Back to My Requests</a>
