<?php
/**
 * Support Contact — NexusFix
 * Professional contact form with dark/light mode, validation, and success feedback.
 */

declare(strict_types=1);
require_once __DIR__.'/../../includes/bootstrap.php';

// Allow both guests and customers
$role = $_SESSION['user']['role'] ?? null;
$user = $_SESSION['user'] ?? null;

$errors = [];
$success = false;

// Pre-fill from session or guest input
$old = [
  'name'    => $user['name'] ?? ($_POST['name'] ?? ''),
  'email'   => $user['email'] ?? ($_POST['email'] ?? ''),
  'subject' => $_POST['subject'] ?? '',
  'message' => $_POST['message'] ?? ''
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_verify($_POST['csrf'] ?? '')) {
    $errors['csrf'] = 'Invalid session token. Please try again.';
  }

  $name    = trim($_POST['name'] ?? '');
  $email   = trim($_POST['email'] ?? '');
  $subject = trim($_POST['subject'] ?? '');
  $message = trim($_POST['message'] ?? '');

  $old = compact('name', 'email', 'subject', 'message');

  if (!$name) $errors['name'] = 'Your name is required.';
  if (!$email) {
    $errors['email'] = 'Email is required.';
  } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors['email'] = 'Please enter a valid email address.';
  }
  if (!$message || strlen($message) < 10) {
    $errors['message'] = 'Please provide a detailed message (at least 10 characters).';
  }

  if (!$errors) {
    try {
      $pdo = db();
      $stmt = $pdo->prepare("INSERT INTO support_tickets (user_id, name, email, subject, message, status) VALUES (?, ?, ?, ?, ?, 'Open')");
      $stmt->execute([
        $user['id'] ?? null,
        $name,
        $email,
        $subject ?: null,
        $message
      ]);

      // Optional: Send notification to admin
      // notify_admin("New Support Ticket", "$name submitted a new support request.");

      $success = true;
      // Clear form on success
      $old = ['name' => '', 'email' => '', 'subject' => '', 'message' => ''];
    } catch (Exception $e) {
      $errors['fatal'] = 'Failed to send message. Please try again later.';
      error_log("Support ticket insert error: " . $e->getMessage());
    }
  }
}
?>
<!doctype html>
<html lang="en" data-theme="dark">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="description" content="Contact NexusFix support for help with repairs, billing, or technical issues. We respond within 24 hours.">
  <title>Contact Support — NexusFix</title>
  <link rel="stylesheet" href="../../assets/css/styles.css">
  <style>
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
      transition: background 0.3s ease;
    }

    main {
      max-width: 720px;
      margin: 2rem auto;
      padding: 1rem;
    }

    h2 {
      font-size: 1.75rem;
      font-weight: 600;
      margin-bottom: 0.5rem;
    }

    .subtitle {
      color: var(--muted);
      font-size: 0.95rem;
      margin-bottom: 1.5rem;
    }

    /* Form */
    .contact-form {
      background: var(--card);
      border: 1px solid var(--border);
      border-radius: var(--radius);
      padding: 1.5rem;
      box-shadow: var(--shadow-sm);
    }

    .form-group {
      margin-bottom: 1rem;
    }

    .form-group label {
      display: block;
      font-weight: 600;
      font-size: 0.95rem;
      margin-bottom: 6px;
      color: var(--text);
    }

    .form-group input,
    .form-group textarea {
      width: 100%;
      padding: 0.75rem 0.9rem;
      border: 1px solid var(--field-border);
      border-radius: 12px;
      background: var(--card);
      color: var(--text);
      font-size: 1rem;
    }

    .form-group textarea {
      min-height: 150px;
      resize: vertical;
    }

    .form-group input:focus,
    .form-group textarea:focus {
      outline: none;
      border-color: var(--primary);
      box-shadow: 0 0 0 3px rgba(96, 165, 250, 0.2);
    }

    /* Messages */
    .alert {
      padding: 1rem;
      border-radius: 12px;
      margin-bottom: 1.5rem;
      font-size: 0.95rem;
    }

    .alert.success {
      background: rgba(52, 211, 153, 0.15);
      border: 1px solid rgba(52, 211, 153, 0.3);
      color: var(--accent);
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

    /* Actions */
    .actions {
      display: flex;
      gap: 12px;
      margin-top: 1.5rem;
      flex-wrap: wrap;
    }

    .btn {
      padding: 0.75rem 1.25rem;
      border-radius: 12px;
      border: 1px solid transparent;
      background: rgba(255,255,255,0.06);
      color: var(--text);
      font-weight: 500;
      cursor: pointer;
      text-decoration: none;
      transition: var(--transition);
      font-size: 1rem;
      display: inline-flex;
      align-items: center;
      gap: 0.5rem;
    }

    .btn.primary {
      background: var(--primary);
      color: white;
    }

    .btn.primary:hover {
      background: #4f9cf9;
      transform: translateY(-1px);
    }

    .btn.subtle {
      background: transparent;
      color: var(--muted);
      border: 1px solid var(--field-border);
    }

    .btn.subtle:hover {
      background: rgba(255,255,255,0.06);
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

  <main aria-labelledby="page-title">
    <!-- REMOVED: Duplicate theme toggle HTML block -->
    <div style="display:flex;justify-content:space-between;align-items:start;flex-wrap:wrap;gap:1rem;margin-bottom:1.5rem;">
      <div>
        <h2 id="page-title">Contact Support</h2>
        <p class="subtitle">Have a question about your repair, account, or service? We're here to help.</p>
      </div>
      <!-- The theme toggle is now handled by header.php, no need to duplicate it here -->
    </div>
    <!-- END REMOVAL -->

    <?php if ($success): ?>
      <div class="alert success" role="alert">
        <strong>Thank you!</strong> Your message has been sent. We'll respond within 24 hours.
      </div>
    <?php else: ?>

      <?php if ($errors): ?>
        <div class="alert error" role="alert">
          <strong>Please fix the following:</strong>
          <ul>
            <?php foreach ($errors as $m): ?>
              <li><?= e($m) ?></li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>

      <form method="post" class="contact-form" novalidate>
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

        <div class="form-group">
          <label for="name">Your Full Name *</label>
          <input
            type="text"
            id="name"
            name="name"
            value="<?= e($old['name']) ?>"
            required
          >
        </div>

        <div class="form-group">
          <label for="email">Email Address *</label>
          <input
            type="email"
            id="email"
            name="email"
            value="<?= e($old['email']) ?>"
            required
            placeholder="We'll respond here"
          >
        </div>

        <div class="form-group">
          <label for="subject">Subject (Optional)</label>
          <input
            type="text"
            id="subject"
            name="subject"
            value="<?= e($old['subject']) ?>"
            placeholder="e.g. Repair Status, Billing Issue"
          >
        </div>

        <div class="form-group">
          <label for="message">Your Message *</label>
          <textarea
            id="message"
            name="message"
            required
            placeholder="Please describe your issue in detail..."><?= e($old['message']) ?></textarea>
        </div>

        <div class="actions">
          <button type="submit" class="btn primary">Send Message</button>
          <?php if (!empty($_SESSION['user']) && $_SESSION['user']['role'] === 'customer'): ?>
            <a href="<?= e(base_url('customer/dashboard.php')) ?>" class="btn subtle">Back to Dashboard</a>
          <?php else: ?>
            <a href="<?= e(base_url('index.php')) ?>" class="btn subtle">Back to Home</a>
          <?php endif; ?>
        </div>
      </form>
    <?php endif; ?>
  </main>

  <!-- REMOVED: Duplicate theme toggle script block -->
  <!-- The theme logic is now handled by header.php -->
  <!-- END REMOVAL -->

</body>
</html>