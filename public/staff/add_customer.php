<?php
// /htdocs/it_repair/public/staff/add_customer.php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/guard.php';
require_once __DIR__ . '/../../config/db.php';

require_role('staff','admin');

// Force JSON response
header('Content-Type: application/json');

$data = json_decode(file_get_contents('php://input'), true);
if (!$data) {
    echo json_encode(['error' => 'Invalid request']); exit;
}

$name  = trim($data['name'] ?? '');
$email = trim($data['email'] ?? '');
$phone = trim($data['phone'] ?? '');
$pass  = $data['password'] ?? '';

if ($name === '' || $email === '' || $pass === '') {
    echo json_encode(['error' => 'Name, email and password are required']); exit;
}

// Check email unique
$chk = db()->prepare("SELECT id FROM users WHERE email = ?");
$chk->execute([$email]);
if ($chk->fetch()) {
    echo json_encode(['error' => 'Email already exists']); exit;
}

// Insert new customer
$hash = password_hash($pass, PASSWORD_DEFAULT);
$stmt = db()->prepare("INSERT INTO users (name,email,phone,password_hash,role,created_at) VALUES (?,?,?,?,?,NOW())");
$stmt->execute([$name,$email,$phone,$hash,'customer']);


$id = (int)db()->lastInsertId();

echo json_encode([
    'id'    => $id,
    'name'  => $name,
    'email' => $email,
    'phone' => $phone
]);
