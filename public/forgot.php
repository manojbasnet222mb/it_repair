<?php
// /htdocs/it_repair/public/forgot.php
session_start();
require_once __DIR__.'/../includes/functions.php';
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../config/db.php';

$errors = [];
$note = null;
$old = ['email'=>''];

if ($_SERVER['REQUEST_METHOD']==='POST') {
  if (!csrf_verify($_POST['csrf'] ?? '')) {
    $errors['csrf']='Invalid session token';
  }

  $email = trim($_POST['email'] ?? '');
  $old['email'] = $email;

  if (!$errors) {
    // 🔧 TODO: Replace with actual password reset logic
    // E.g. generate token, save in DB, send email link
    $user = auth_find_user_by_email($email);
    if ($user) {
      $note = "If this email exists in our system, a reset link has been sent.";
      // Example: store a reset request
      // db()->prepare("INSERT INTO password_resets (user_id,token,expires_at) VALUES (?,?,?)")
      //   ->execute([$user['id'],$token,$expiry]);
    } else {
      $note = "If this email exists in our system, a reset link has been sent.";
    }
  }
}

// Build form HTML
ob_start(); ?>
<form method="post" class="mini-form" style="max-width:520px; display:grid; gap:10px;">
  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
  <input name="email" type="email" placeholder="Your account email" required value="<?= e($old['email']) ?>">
  <button class="btn primary">Send Reset Link</button>
  <a class="btn subtle" href="<?= e(base_url('login.php')) ?>">← Back to login</a>
</form>
<?php $form = ob_get_clean();

// Page metadata
$title = "Forgot Password";
$subtitle = "Enter your account email and we’ll send you a reset link.";
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?= e($title) ?> — NexusFix</title>
  <link rel="stylesheet" href="../assets/css/styles.css">
</head>
<body>
  <?php require __DIR__.'/../includes/header.php'; ?>

  <?php if($note): ?>
    <div class="card" style="max-width:520px;margin:20px auto;text-align:center;">
      <?= e($note) ?>
    </div>
  <?php endif; ?>

  <?php require __DIR__.'/../includes/auth_form.php'; ?>
  <script src="../assets/js/app.js"></script>
</body>
</html>
