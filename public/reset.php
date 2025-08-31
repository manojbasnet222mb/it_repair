<?php
// /htdocs/it_repair/public/reset.php
session_start();
require_once __DIR__.'/../includes/functions.php';
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../config/db.php';

$errors = [];
$note = null;
$old = ['password'=>'','password2'=>''];

// token from link
$token = $_GET['token'] ?? '';
if (!$token) {
  $errors['token'] = "Missing reset token.";
}

// Example: check reset token (you need a password_resets table)
if ($token && $_SERVER['REQUEST_METHOD'] !== 'POST') {
  $stmt = db()->prepare("SELECT pr.*, u.id AS user_id, u.email 
    FROM password_resets pr
    JOIN users u ON u.id=pr.user_id
    WHERE pr.token=? AND pr.expires_at > NOW()");
  $stmt->execute([$token]);
  $reset = $stmt->fetch();
  if (!$reset) {
    $errors['token'] = "Invalid or expired token.";
  }
}

if ($_SERVER['REQUEST_METHOD']==='POST') {
  if (!csrf_verify($_POST['csrf'] ?? '')) $errors['csrf'] = "Invalid session token";
  $password  = $_POST['password'] ?? '';
  $password2 = $_POST['password2'] ?? '';
  $token     = $_POST['token'] ?? '';

  if (!$errors) {
    if ($password !== $password2) $errors['password'] = "Passwords do not match";
    elseif (strlen($password) < 6) $errors['password'] = "Password must be at least 6 chars";

    if (!$errors) {
      // Lookup reset record
      $stmt = db()->prepare("SELECT pr.*, u.id AS user_id 
        FROM password_resets pr
        JOIN users u ON u.id=pr.user_id
        WHERE pr.token=? AND pr.expires_at > NOW()");
      $stmt->execute([$token]);
      $reset = $stmt->fetch();

      if ($reset) {
        // Update user password
        $hash = password_hash($password, PASSWORD_BCRYPT);
        db()->prepare("UPDATE users SET password_hash=? WHERE id=?")
          ->execute([$hash, $reset['user_id']]);

        // Delete used reset token
        db()->prepare("DELETE FROM password_resets WHERE id=?")->execute([$reset['id']]);

        $note = "Password updated successfully. You can now <a href='".e(base_url('login.php'))."'>login</a>.";
      } else {
        $errors['token'] = "Invalid or expired reset link.";
      }
    }
  }
}

// Build form HTML
ob_start(); ?>
<form method="post" class="mini-form" style="max-width:520px; display:grid; gap:10px;">
  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
  <input type="hidden" name="token" value="<?= e($token) ?>">
  <input name="password" type="password" placeholder="New password" required>
  <input name="password2" type="password" placeholder="Confirm new password" required>
  <button class="btn primary">Reset Password</button>
  <a class="btn subtle" href="<?= e(base_url('login.php')) ?>">← Back to login</a>
</form>
<?php $form = ob_get_clean();

// Page metadata
$title = "Reset Password";
$subtitle = "Enter a new password for your account.";
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
      <?= $note ?>
    </div>
  <?php endif; ?>

  <?php if(!$note): ?>
    <?php require __DIR__.'/../includes/auth_form.php'; ?>
  <?php endif; ?>

  <script src="../assets/js/app.js"></script>
</body>
</html>
