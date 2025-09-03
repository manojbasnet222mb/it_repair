<?php
declare(strict_types=1);

// Ensure config file exists
if (!file_exists(__DIR__ . '/../config/db.php')) {
    die('Configuration error: Database configuration file is missing.');
}

/* ===== Harden session cookie parameters BEFORE starting session ===== */
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    // Only show errors in development. Check for common dev indicators.
    // This is a more robust way than just relying on ini_set in the file.
    $isDevEnvironment = (
        isset($_SERVER['APP_ENV']) && $_SERVER['APP_ENV'] === 'development'
    ) || (
        isset($_SERVER['SERVER_NAME']) && (
            $_SERVER['SERVER_NAME'] === 'localhost' ||
            $_SERVER['SERVER_NAME'] === '127.0.0.1' ||
            strpos($_SERVER['SERVER_NAME'], '.local') !== false ||
            strpos($_SERVER['SERVER_NAME'], '.test') !== false
        )
    ) || (PHP_SAPI === 'cli');

    if (!$isDevEnvironment) {
        ini_set('display_errors', '0'); // Force off for non-dev
    } else {
        // Respect php.ini setting for development, but ensure logging is on
        ini_set('log_errors', '1');
    }

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
  // Ensure app_log function is available or use a fallback
  if (!function_exists('app_log')) {
    error_log('[fatal] '.$e->getMessage().' @ '.$e->getFile().':'.$e->getLine());
  } else {
    app_log('error', $e->getMessage(), [
      'file' => $e->getFile(),
      'line' => $e->getLine()
    ]);
  }
  // Don't send detailed errors in production
  $isDevEnvironment = (
      isset($_SERVER['APP_ENV']) && $_SERVER['APP_ENV'] === 'development'
  ) || (
      isset($_SERVER['SERVER_NAME']) && (
          $_SERVER['SERVER_NAME'] === 'localhost' ||
          $_SERVER['SERVER_NAME'] === '127.0.0.1' ||
          strpos($_SERVER['SERVER_NAME'], '.local') !== false ||
          strpos($_SERVER['SERVER_NAME'], '.test') !== false
      )
  ) || (PHP_SAPI === 'cli');

  http_response_code(500);
  if ($isDevEnvironment) {
      echo "<h1>Something went wrong</h1>";
      echo "<p><strong>Error:</strong> " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . "</p>";
      echo "<p><strong>File:</strong> " . htmlspecialchars($e->getFile(), ENT_QUOTES, 'UTF-8') . "</p>";
      echo "<p><strong>Line:</strong> " . htmlspecialchars((string)$e->getLine(), ENT_QUOTES, 'UTF-8') . "</p>";
      // Note: Showing full trace is helpful for dev but should NEVER be done in production
      echo "<h2>Stack Trace:</h2><pre>" . htmlspecialchars($e->getTraceAsString(), ENT_QUOTES, 'UTF-8') . "</pre>";
  } else {
      echo "<h1>Something went wrong</h1><p>An error occurred. Please try again later.</p>";
      // Log the full details server-side
      error_log("Unhandled Exception: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine());
  }
});

/* ===== App includes ===== */
require_once __DIR__.'/functions.php';
require_once __DIR__.'/auth.php';
require_once __DIR__.'/guard.php';
require_once __DIR__.'/../config/db.php'; // Already checked above

/* ===== Per-request computed values ===== */
if (isset($_SESSION['user']) && $_SESSION['user']['role'] === 'customer') {
    try {
        $stmt = db()->prepare(
          "SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0"
        );
        $stmt->execute([$_SESSION['user']['id']]);
        $_SESSION['unread_notifications_count'] = (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        // Use error_log if app_log is not yet available or has issues
        if (function_exists('app_log')) {
            app_log('error', 'unread notifications count failed', ['err'=>$e->getMessage()]);
        } else {
            error_log("Unread notifications count failed: " . $e->getMessage());
        }
        $_SESSION['unread_notifications_count'] = 0;
    }
} else {
    $_SESSION['unread_notifications_count'] = 0;
}