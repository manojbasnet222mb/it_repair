<?php
session_start();
require_once __DIR__.'/../../includes/functions.php';
require_once __DIR__.'/../../includes/guard.php';
require_once __DIR__.'/../../config/db.php';
require_role('admin');

$errors = [];
$note = null;

if ($_SERVER['REQUEST_METHOD']==='POST') {
  if (!csrf_verify($_POST['csrf'] ?? '')) $errors['csrf']='Invalid token';
  $act = $_POST['act'] ?? '';

  if (!$errors) {
    if ($act==='create') {
      $name = trim($_POST['name']??'');
      $email = trim($_POST['email']??'');
      $role = $_POST['role'] ?? 'staff';
      $pwd  = $_POST['password'] ?? '';
      if(!filter_var($email,FILTER_VALIDATE_EMAIL)) $errors['email']='Invalid email';
      if(strlen($pwd)<6) $errors['password']='Min 6 chars';
      if(!$name) $errors['name']='Name required';
      if(!in_array($role,['staff','admin','customer'],true)) $errors['role']='Invalid role';

      if(!$errors){
        $ex = db()->prepare("SELECT id FROM users WHERE email=?"); $ex->execute([$email]);
        if($ex->fetch()){ $errors['email']='Email exists'; }
        else{
          $hash = password_hash($pwd, PASSWORD_DEFAULT);
          $stmt = db()->prepare("INSERT INTO users (role,name,email,password_hash) VALUES (?,?,?,?)");
          $stmt->execute([$role,$name,$email,$hash]);
          $note='User created';
        }
      }
    } elseif ($act==='role') {
      $uid = (int)($_POST['uid']??0);
      $role = $_POST['role'] ?? 'staff';
      if(!in_array($role,['staff','admin','customer'],true)) $errors['role']='Invalid role';
      if(!$errors){
        $u = db()->prepare("UPDATE users SET role=? WHERE id=?");
        $u->execute([$role,$uid]); $note='Role updated';
      }
    } elseif ($act==='resetpw') {
      $uid = (int)($_POST['uid']??0);
      $pwd  = $_POST['password'] ?? '';
      if(strlen($pwd)<6) $errors['password']='Min 6 chars';
      if(!$errors){
        $u = db()->prepare("UPDATE users SET password_hash=? WHERE id=?");
        $u->execute([password_hash($pwd,PASSWORD_DEFAULT),$uid]);
        $note='Password reset';
      }
    }
  }
}

$users = db()->query("SELECT id,name,email,role,created_at FROM users ORDER BY id DESC")->fetchAll();
?>
<!doctype html>
<html><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Admin — Users</title>
<link rel="stylesheet" href="../../assets/css/styles.css">
</head><body>
<?php require __DIR__.'/../../includes/header.php'; ?>
<main class="how">
  <h2>Admin — Users</h2>
  <?php if($note): ?><div class="card"><strong><?= e($note) ?></strong></div><?php endif; ?>
  <?php if($errors): ?><div class="card"><?php foreach($errors as $m): ?><div><?= e($m) ?></div><?php endforeach; ?></div><?php endif; ?>

  <div class="cards" style="grid-template-columns:1fr;">
    <article class="card">
      <h3>Create Staff/Admin</h3>
      <form method="post" class="mini-form" style="display:grid;grid-template-columns:repeat(5,1fr);gap:8px;">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input name="name" placeholder="Full name" required>
        <input name="email" type="email" placeholder="Email" required>
        <select name="role">
          <option value="staff">staff</option>
          <option value="admin">admin</option>
        </select>
        <input name="password" type="password" placeholder="Temp password" required>
        <button class="btn primary" name="act" value="create">Create</button>
      </form>
    </article>

    <article class="card">
      <h3>All Users</h3>
      <div class="trust">
        <ul style="grid-template-columns:1fr;">
          <?php foreach($users as $u): ?>
            <li style="display:grid;grid-template-columns:2fr 2fr 1fr 2fr;gap:8px;align-items:center;">
              <div><strong><?= e($u['name']) ?></strong><br><span class="tiny"><?= e($u['email']) ?></span></div>
              <div>Created: <?= e($u['created_at']) ?></div>
              <div>Role: <strong><?= e($u['role']) ?></strong></div>
              <form method="post" class="mini-form" style="display:flex;gap:6px;justify-content:flex-end;">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="uid" value="<?= e($u['id']) ?>">
                <select name="role">
                  <option <?= $u['role']==='customer'?'selected':'' ?>>customer</option>
                  <option <?= $u['role']==='staff'?'selected':'' ?>>staff</option>
                  <option <?= $u['role']==='admin'?'selected':'' ?>>admin</option>
                </select>
                <button class="btn outline" name="act" value="role">Change Role</button>
                <input name="password" type="password" placeholder="New password">
                <button class="btn subtle" name="act" value="resetpw">Reset PW</button>
              </form>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>
    </article>
  </div>
</main>
<script src="../../assets/js/app.js"></script>
</body></html>
