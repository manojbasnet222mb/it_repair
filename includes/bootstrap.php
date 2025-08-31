<?php
declare(strict_types=1);

/* ===== Harden session cookie parameters BEFORE starting session ===== */
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('display_errors', '0');  // For local dev you can enable via php.ini if needed
    ini_set('log_errors', '1');

    session_set_cookie_params([
      'lifetime' => 0,
      'path'     => '/',
      'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
      'httponly' => true,
      'samesite' => 'Lax',
    ]);

    session_start();
}

/* ===== Runtime hardening & global handlers ===== */
set_error_handler(function($severity, $message, $file, $line) {
  if (!(error_reporting() & $severity)) return;
  throw new ErrorException($message, 0, $severity, $file, $line);
});

set_exception_handler(function($e) {
  if (!function_exists('app_log')) {
    error_log('[fatal] '.$e->getMessage().' @ '.$e->getFile().':'.$e->getLine());
  } else {
    app_log('error', $e->getMessage(), [
      'file' => $e->getFile(),
      'line' => $e->getLine()
    ]);
  }
  http_response_code(500);
  echo "<h1>Something went wrong</h1><p>Please try again.</p>";
});

/* ===== App includes ===== */
require_once __DIR__.'/functions.php';
require_once __DIR__.'/auth.php';
require_once __DIR__.'/guard.php';
require_once __DIR__.'/../config/db.php';

/* ===== Per-request computed values ===== */
if (isset($_SESSION['user']) && $_SESSION['user']['role'] === 'customer') {
    try {
        $stmt = db()->prepare(
          "SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0"
        );
        $stmt->execute([$_SESSION['user']['id']]);
        $_SESSION['unread_notifications_count'] = (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        app_log('error', 'unread notifications count failed', ['err'=>$e->getMessage()]);
        $_SESSION['unread_notifications_count'] = 0;
    }
} else {
    $_SESSION['unread_notifications_count'] = 0;
}
