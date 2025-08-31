<?php
// /htdocs/it_repair/public/util/set_admin_password.php
require_once __DIR__ . '/../../includes/bootstrap.php';

// CHANGE THESE:
$email = 'admin@nexusfix.local';   // existing admin email from your DB dump
$newPlain = 'Admin@12345';         // new password you want to set

$hash = password_hash($newPlain, PASSWORD_DEFAULT);
$st = db()->prepare("UPDATE users SET password_hash=? WHERE email=? AND role='admin'");
$st->execute([$hash, $email]);

echo "Password updated for {$email}. New password: {$newPlain}";
