<?php
// /htdocs/it_repair/includes/auth.php
require_once __DIR__ . '/../config/db.php';

/* ===== User lookup ===== */
function auth_find_user_by_email(string $email){
  $stmt = db()->prepare("SELECT * FROM users WHERE email = ?");
  $stmt->execute([$email]);
  return $stmt->fetch();
}

/* ===== Registration (customer) minimal example =====
   NOTE: adjust fields to your exact `users` table columns if they differ.
*/
function auth_register_customer(array $data, array &$errors): bool {
  if (!filter_var($data['email'] ?? '', FILTER_VALIDATE_EMAIL)) $errors['email']='Invalid email';
  if (strlen((string)($data['password'] ?? '')) < 6) $errors['password']='Min 6 characters';
  if (($data['password'] ?? '') !== ($data['password2'] ?? '')) $errors['password2']='Passwords do not match';
  if (empty($data['name'])) $errors['name']='Name is required';
  if ($errors) return false;

  if (auth_find_user_by_email($data['email'])) {
    $errors['email'] = 'Email already registered';
    return false;
  }

  $hash = password_hash($data['password'], PASSWORD_DEFAULT);
  $stmt = db()->prepare("INSERT INTO users (role,name,email,phone,password_hash) VALUES ('customer',?,?,?,?)");
  $stmt->execute([$data['name'], $data['email'], $data['phone'] ?? null, $hash]);
  return true;
}

/* ===== Attempt login ===== */
function auth_attempt_login(string $email, string $password, array &$errors): ?array {
  $user = auth_find_user_by_email($email);
  if (!$user || empty($user['password_hash']) || !password_verify($password, $user['password_hash'])) {
    $errors['login'] = 'Invalid credentials';
    return null;
  }
  return $user;
}

/* ===== New: login/logout session helpers ===== */
function auth_login(array $user): void {
  session_regenerate_id(true);
  $_SESSION['user'] = [
    'id'    => (int)$user['id'],
    'role'  => $user['role'],
    'name'  => $user['name'],
    'email' => $user['email'],
  ];
}
function auth_logout(): void {
  $_SESSION = [];
  if (ini_get("session.use_cookies")) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $p["path"], $p["domain"], $p["secure"], $p["httponly"]);
  }
  session_destroy();
}
