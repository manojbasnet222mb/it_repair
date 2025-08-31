<?php
require_once __DIR__.'/../../includes/bootstrap.php';
require_role('customer');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_verify($_POST['csrf'] ?? '')) die("Invalid session");

  $invoice_id = (int)($_POST['invoice_id'] ?? 0);
  if (!$invoice_id) die("Missing invoice");

  // Load invoice
  $st = db()->prepare("SELECT * FROM invoices WHERE id=? AND payment_status='Unpaid'");
  $st->execute([$invoice_id]);
  $invoice = $st->fetch();
  if (!$invoice) die("Invoice not found or already paid.");

  // Stripe Checkout session (test mode)
  require_once __DIR__ . '/../../vendor/autoload.php'; // Stripe SDK

  \Stripe\Stripe::setApiKey('sk_test_yourSecretKeyHere'); // replace with your secret key

  $checkout = \Stripe\Checkout\Session::create([
    'payment_method_types' => ['card'],
    'line_items' => [[
      'price_data' => [
        'currency' => 'usd',
        'product_data' => [
          'name' => 'Invoice #' . $invoice['id'],
        ],
        'unit_amount' => 10000, // in cents, e.g. $100
      ],
      'quantity' => 1,
    ]],
    'mode' => 'payment',
    'success_url' => 'https://yourdomain.com/public/customer/payment_success.php?invoice_id=' . $invoice['id'],
    'cancel_url' => 'https://yourdomain.com/public/customer/payment_failed.php?invoice_id=' . $invoice['id'],
  ]);

  header("Location: " . $checkout->url);
  exit;
}
