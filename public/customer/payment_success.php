<?php
require_once __DIR__.'/../../includes/bootstrap.php';
$invoice_id = (int)($_GET['invoice_id'] ?? 0);

if ($invoice_id) {
  $up = db()->prepare("UPDATE invoices SET payment_status='Paid', payment_date=NOW() WHERE id=?");
  $up->execute([$invoice_id]);
}
?>
<h2>Payment Successful ✅</h2>
<p>Your payment has been recorded. Thank you!</p>
<a href="requests.php">Back to My Requests</a>
