<?php
// /htdocs/it_repair/config/db.php
// Reads credentials from environment variables with sane defaults for local dev.

function env(string $key, $default = null) {
  $v = getenv($key);
  return $v !== false ? $v : $default;
}

function db(): PDO {
  static $pdo;
  if ($pdo) return $pdo;

  $host = env('DB_HOST', '127.0.0.1');
  $port = (int)env('DB_PORT', '3306');
  // IMPORTANT: default matches your dump name
  $name = env('DB_NAME', 'it_repair_db');
  $user = env('DB_USER', 'root');
  $pass = env('DB_PASS', '');

  $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
  $pdo = new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
  ]);
  return $pdo;
}
