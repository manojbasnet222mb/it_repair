<?php
/**
 * Login — NexusFix (World-Class Edition)
 * Inspired by top-tier design systems for a seamless, trustworthy sign-in experience.
 */

declare(strict_types=1);

session_start();
require_once __DIR__.'/../includes/functions.php';
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../config/db.php';

$title = 'Sign In';
$errors = [];
$old = ['email' => $_POST['email'] ?? ''];
$step = $_SESSION['login_step'] ?? 1; // Track login step (1 = email, 2 = password)
$email = $_SESSION['login_email'] ?? ''; // Store email between steps

/* === Advanced Attempt-Based Throttling === */
// Allow 5 initial attempts before throttling
// Then progressive throttling: [cycle] => [lockout_seconds, retry_attempts]
$throttleRules = [
    1 => [15, 2],    // 15 seconds lockout, 2 retry attempts
    2 => [30, 2],    // 30 seconds lockout, 2 retry attempts
    3 => [60, 2],    // 1 minute lockout, 2 retry attempts
    4 => [300, 2],   // 5 minutes lockout, 2 retry attempts
    5 => [900, 2],   // 15 minutes lockout, 2 retry attempts
    6 => [1800, 0]   // 30 minutes lockout, 0 retry attempts (final)
];

$now = time();

// Initialize session variables for email-specific tracking
if (!isset($_SESSION['login_attempts_per_email'])) {
    $_SESSION['login_attempts_per_email'] = []; // Store attempts per email
}

// Handle back button from step 2
if (isset($_GET['back']) && $_GET['back'] === '1') {
    $_SESSION['login_step'] = 1;
    $step = 1;
}

// Get attempt data for current email
$currentEmailAttempts = 0;
$currentEmailAttemptCycle = 0;
$currentEmailCycleAttempts = 0;
$currentEmailLastAttempt = 0;
$currentEmailThrottleEnd = 0;
$currentEmailRetryAttemptsLeft = 0;

if (!empty($_SESSION['login_email'])) {
    $emailKey = $_SESSION['login_email'];
    if (isset($_SESSION['login_attempts_per_email'][$emailKey])) {
        $attemptData = $_SESSION['login_attempts_per_email'][$emailKey];
        $currentEmailAttempts = $attemptData['attempts'] ?? 0;
        $currentEmailAttemptCycle = $attemptData['attempt_cycle'] ?? 0;
        $currentEmailCycleAttempts = $attemptData['cycle_attempts'] ?? 0;
        $currentEmailLastAttempt = $attemptData['last_login_attempt'] ?? 0;
        $currentEmailThrottleEnd = $attemptData['throttle_end_time'] ?? 0;
        $currentEmailRetryAttemptsLeft = $attemptData['retry_attempts_left'] ?? 0;
    }
}

$showThrottleMessage = false;
$throttleEndTime = 0;
$retryAttemptsLeft = 0;
$totalAttempts = $currentEmailAttempts;
$currentLockoutDuration = 0;
$nextLockoutDuration = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf'] ?? '')) {
        $errors['csrf'] = 'Invalid session token. Please refresh and try again.';
    }

    // Check if user is currently throttled for the current email
    if (empty($errors) && $currentEmailThrottleEnd > $now) {
        $showThrottleMessage = true;
        $throttleEndTime = $currentEmailThrottleEnd;
        $retryAttemptsLeft = $currentEmailRetryAttemptsLeft;
        $currentLockoutDuration = $throttleEndTime - $currentEmailLastAttempt;
    }

    if (empty($errors) && !$showThrottleMessage) {
        // Handle step 1: Email verification
        if ($step === 1) {
            $email = trim($_POST['email'] ?? '');
            
            if (empty($email)) {
                $errors['email'] = 'Email is required';
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors['email'] = 'Invalid email format';
            } else {
                $user = auth_find_user_by_email($email);
                if (!$user) {
                    $errors['email'] = 'No account found with this email';
                } else {
                    // Valid email, proceed to password step
                    $_SESSION['login_email'] = $email;
                    $_SESSION['login_step'] = 2;
                    $step = 2;
                    // Redirect to avoid POST on refresh
                    header("Location: " . $_SERVER['PHP_SELF']);
                    exit();
                }
            }
        } 
        // Handle step 2: Password verification
        elseif ($step === 2) {
            $password = $_POST['password'] ?? '';
            $email = $_SESSION['login_email'] ?? '';
            
            if (empty($password)) {
                $errors['password'] = 'Password is required';
            } else {
                $user = auth_attempt_login($email, $password, $errors);
                
                // Update last attempt time
                $currentEmailLastAttempt = $now;
                
                if (!$user) {
                    // Failed login attempt - increment for this specific email
                    $currentEmailAttempts = $currentEmailAttempts + 1;
                    $totalAttempts = $currentEmailAttempts;
                    
                    // Store updated attempt data for this email
                    $_SESSION['login_attempts_per_email'][$email] = [
                        'attempts' => $currentEmailAttempts,
                        'attempt_cycle' => $currentEmailAttemptCycle,
                        'cycle_attempts' => $currentEmailCycleAttempts,
                        'last_login_attempt' => $currentEmailLastAttempt,
                        'throttle_end_time' => $currentEmailThrottleEnd,
                        'retry_attempts_left' => $currentEmailRetryAttemptsLeft
                    ];
                    
                    // Only apply throttling after 5 failed attempts
                    if ($currentEmailAttempts >= 5) {
                        // Determine next lockout duration
                        $nextCycle = $currentEmailAttemptCycle + 1;
                        if (isset($throttleRules[$nextCycle])) {
                            $nextLockoutDuration = $throttleRules[$nextCycle][0];
                        }
                        
                        // Check if we're in a retry cycle or starting a new one
                        if ($currentEmailRetryAttemptsLeft > 0) {
                            // Still in retry phase of current cycle
                            $currentEmailRetryAttemptsLeft = $currentEmailRetryAttemptsLeft - 1;
                            $currentEmailCycleAttempts = $currentEmailCycleAttempts + 1;
                            $retryAttemptsLeft = $currentEmailRetryAttemptsLeft;
                            
                            // Update session data
                            $_SESSION['login_attempts_per_email'][$email]['retry_attempts_left'] = $currentEmailRetryAttemptsLeft;
                            $_SESSION['login_attempts_per_email'][$email]['cycle_attempts'] = $currentEmailCycleAttempts;
                            
                            // If this was the last retry attempt, apply next lockout
                            if ($currentEmailRetryAttemptsLeft == 0) {
                                $cycle = $currentEmailAttemptCycle + 1;
                                if (isset($throttleRules[$cycle])) {
                                    $rule = $throttleRules[$cycle];
                                    $lockSeconds = $rule[0];
                                    $retryAttempts = $rule[1];
                                    
                                    // Apply throttling
                                    $currentEmailAttemptCycle = $cycle;
                                    $currentEmailThrottleEnd = $now + $lockSeconds;
                                    $currentEmailRetryAttemptsLeft = $retryAttempts;
                                    
                                    $showThrottleMessage = true;
                                    $throttleEndTime = $currentEmailThrottleEnd;
                                    $retryAttemptsLeft = $currentEmailRetryAttemptsLeft;
                                    $currentLockoutDuration = $lockSeconds;
                                    $nextLockoutDuration = isset($throttleRules[$cycle + 1]) ? $throttleRules[$cycle + 1][0] : 0;
                                    
                                    // Update session data
                                    $_SESSION['login_attempts_per_email'][$email]['attempt_cycle'] = $currentEmailAttemptCycle;
                                    $_SESSION['login_attempts_per_email'][$email]['throttle_end_time'] = $currentEmailThrottleEnd;
                                    $_SESSION['login_attempts_per_email'][$email]['retry_attempts_left'] = $currentEmailRetryAttemptsLeft;
                                }
                            }
                        } else {
                            // Starting a new cycle (first lockout after 5 attempts)
                            $cycle = max(1, $currentEmailAttemptCycle + 1);
                            if (isset($throttleRules[$cycle])) {
                                $rule = $throttleRules[$cycle];
                                $lockSeconds = $rule[0];
                                $retryAttempts = $rule[1];
                                
                                // Apply throttling
                                $currentEmailAttemptCycle = $cycle;
                                $currentEmailThrottleEnd = $now + $lockSeconds;
                                $currentEmailRetryAttemptsLeft = $retryAttempts;
                                
                                $showThrottleMessage = true;
                                $throttleEndTime = $currentEmailThrottleEnd;
                                $retryAttemptsLeft = $currentEmailRetryAttemptsLeft;
                                $currentLockoutDuration = $lockSeconds;
                                $nextLockoutDuration = isset($throttleRules[$cycle + 1]) ? $throttleRules[$cycle + 1][0] : 0;
                                
                                // Update session data
                                $_SESSION['login_attempts_per_email'][$email]['attempt_cycle'] = $currentEmailAttemptCycle;
                                $_SESSION['login_attempts_per_email'][$email]['throttle_end_time'] = $currentEmailThrottleEnd;
                                $_SESSION['login_attempts_per_email'][$email]['retry_attempts_left'] = $currentEmailRetryAttemptsLeft;
                            }
                        }
                    }
                } else {
                    // Successful login - remove attempt data for this email and reset session
                    unset($_SESSION['login_attempts_per_email'][$email]);
                    $_SESSION['login_step'] = 1; // Reset for next login
                    $_SESSION['login_email'] = ''; // Clear email
                    auth_login($user);

                    if ($user['role'] === 'admin' || $user['role'] === 'staff') {
                        redirect(base_url('staff/dashboard.php'));
                    } else {
                        redirect(base_url('customer/dashboard.php'));
                    }
                }
            }
        }
    }
} else {
    // Check for existing throttle on page load for the current email
    if ($currentEmailThrottleEnd > $now) {
        $showThrottleMessage = true;
        $throttleEndTime = $currentEmailThrottleEnd;
        $retryAttemptsLeft = $currentEmailRetryAttemptsLeft;
        $currentLockoutDuration = $throttleEndTime - $currentEmailLastAttempt;
        
        // Determine next lockout duration
        $nextCycle = $currentEmailAttemptCycle + 1;
        if (isset($throttleRules[$nextCycle])) {
            $nextLockoutDuration = $throttleRules[$nextCycle][0];
        }
    }
    $totalAttempts = $currentEmailAttempts;
    
    // Reset step if coming from a different page
    if (!isset($_SESSION['login_step'])) {
        $_SESSION['login_step'] = 1;
        $_SESSION['login_email'] = '';
    }
    
    // Determine next lockout duration for display
    if ($currentEmailAttempts >= 5) {
        $nextCycle = $currentEmailAttemptCycle + 1;
        if (isset($throttleRules[$nextCycle])) {
            $nextLockoutDuration = $throttleRules[$nextCycle][0];
        }
    }
}

// Helper function to format seconds into human-readable time
function format_duration($seconds) {
    if ($seconds < 60) {
        return $seconds . ' second' . ($seconds != 1 ? 's' : '');
    } elseif ($seconds < 3600) {
        $minutes = floor($seconds / 60);
        return $minutes . ' minute' . ($minutes != 1 ? 's' : '');
    } elseif ($seconds < 86400) {
        $hours = floor($seconds / 3600);
        return $hours . ' hour' . ($hours != 1 ? 's' : '');
    } else {
        $days = floor($seconds / 86400);
        return $days . ' day' . ($days != 1 ? 's' : '');
    }
}
?>
<!doctype html>
<!-- Theme managed by includes/header.php script -->
<html lang="en" data-theme="dark">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="description" content="Sign in to your NexusFix account to manage repairs, track status, and contact support.">
  <title><?= e($title) ?> — NexusFix</title>
  <link rel="stylesheet" href="../assets/css/styles.css">
  <style>
    :root {
      --bg: #0b0c0f;
      --bg-secondary: #101218;
      --card: #101218;
      --card-2: #121622;
      --text: #e8eaf0;
      --text-secondary: #a6adbb;
      --muted: #a6adbb;
      --border: #1f2430;
      --field-border: #2a3242;
      --primary: #60a5fa;
      --primary-hover: #4f9cf9;
      --danger: #f87171;
      --success: #34d399;
      --warning: #fbbf24;
      --shadow: 0 10px 30px rgba(0,0,0,.35);
      --shadow-sm: 0 4px 12px rgba(0,0,0,.18);
      --radius: 16px;
      --radius-sm: 12px;
      --transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
    }

    /* Light Theme - Specificity improved */
    :root[data-theme="light"] {
      --bg: #f7f8fb;
      --bg-secondary: #f0f1f4;
      --card: #ffffff;
      --card-2: #f9fafb;
      --text: #0b0c0f;
      --text-secondary: #5b6172;
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
      line-height: 1.6;
      margin: 0;
      padding: 0;
      transition: background 0.3s ease, color 0.3s ease;
    }

    main {
      display: flex;
      justify-content: center;
      align-items: center;
      min-height: 100vh;
      padding: 1rem;
      background: var(--bg-secondary);
    }

    /* Login Card */
    .login-card {
      background: linear-gradient(180deg, var(--card), var(--card-2));
      border: 1px solid var(--border);
      border-radius: var(--radius);
      padding: 2.5rem;
      box-shadow: var(--shadow-sm);
      width: 100%;
      max-width: 460px;
      position: relative;
      transition: var(--transition);
    }

    .login-card:hover {
        box-shadow: var(--shadow);
    }

    /* Branding Section */
    .login-branding {
        text-align: center;
        margin-bottom: 2rem; /* Space below the branding */
    }

    .login-logo {
        /* Styles for the SVG logo */
        width: 48px; /* Adjust size as needed */
        height: 48px;
        margin: 0 auto 12px; /* Center logo and add space below */
        display: block;
    }

    .login-brand-name {
        /* Styles for the brand name */
        font-weight: 700;
        font-size: 1.75rem; /* Adjust size as needed */
        /* Gradient applied via HTML style for simplicity, can be moved to CSS */
        background: linear-gradient(90deg,#ec4899,#8b5cf6,#3b82f6,#22c55e,#eab308);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        background-clip: text;
        color: transparent;
        text-decoration: none; /* Remove default link underline */
        display: block; /* Ensure it behaves like a block for centering */
    }

    h1#page-title {
      font-size: 1.5rem; /* Slightly smaller title now that branding is above */
      font-weight: 600;
      margin: 0 0 0.5rem; /* Reduced top/bottom margin */
      text-align: center;
      color: var(--text);
    }

    .subtitle {
      color: var(--text-secondary);
      font-size: 0.95rem;
      text-align: center;
      margin: 0 0 1.5rem; /* Reduced bottom margin */
    }

    /* Form Fields */
    .field {
      margin-bottom: 1.25rem;
    }

    .field label {
      display: block;
      font-weight: 600;
      font-size: 0.95rem;
      margin-bottom: 0.5rem;
      color: var(--text);
    }

    .field input {
      width: 100%;
      padding: 0.85rem 1rem;
      border: 1px solid var(--field-border);
      border-radius: var(--radius-sm);
      background: var(--card);
      color: var(--text);
      font-size: 1rem;
      transition: var(--transition);
    }

    .field input:focus {
      outline: none;
      border-color: var(--primary);
      box-shadow: 0 0 0 3px rgba(96, 165, 250, 0.2);
    }

    /* Individual Field Errors */
    .field-error {
      color: var(--danger);
      font-size: 0.85rem;
      margin-top: 0.25rem;
      font-weight: 500;
    }

    /* Password Toggle */
    .password-field {
      position: relative;
    }

    .toggle-password {
      position: absolute;
      right: 12px;
      top: 50%;
      transform: translateY(-50%);
      background: none;
      border: none;
      color: var(--muted);
      font-size: 0.9rem;
      cursor: pointer;
      padding: 0.25rem 0.5rem;
      border-radius: 4px;
      transition: var(--transition);
    }

    .toggle-password:hover {
      color: var(--primary);
      background: rgba(96, 165, 250, 0.1);
    }

    /* Messages */
    .alert {
      background: rgba(248, 113, 113, 0.15);
      border: 1px solid rgba(248, 113, 113, 0.3);
      color: var(--danger);
      border-radius: var(--radius-sm);
      padding: 1rem 1.25rem;
      margin-bottom: 1.5rem;
      font-size: 0.95rem;
      font-weight: 500;
    }

    /* Throttle Countdown */
    .throttle-countdown {
      background: rgba(251, 191, 36, 0.15);
      border: 1px solid rgba(251, 191, 36, 0.3);
      color: var(--warning);
      border-radius: var(--radius-sm);
      padding: 1rem 1.25rem;
      margin-bottom: 1.5rem;
      font-size: 0.95rem;
      font-weight: 500;
    }

    .throttle-countdown strong {
      font-weight: 600;
    }

    .throttle-details {
      margin-top: 0.5rem;
      font-size: 0.85rem;
      color: var(--text-secondary);
    }

    /* Attempt Counter */
    .attempt-counter {
      text-align: center;
      color: var(--text-secondary);
      font-size: 0.9rem;
      margin-bottom: 1rem;
    }

    .attempt-counter strong {
      color: var(--warning);
    }

    /* Progress Bar */
    .progress-bar {
      height: 6px;
      background: var(--border);
      border-radius: 3px;
      margin: 1rem 0;
      overflow: hidden;
    }

    .progress-fill {
      height: 100%;
      background: var(--warning);
      border-radius: 3px;
      transition: width 0.3s ease;
    }

    /* Buttons */
    .btn {
      width: 100%;
      padding: 0.85rem 1.25rem;
      border-radius: var(--radius-sm);
      border: 1px solid transparent;
      background: var(--primary);
      color: white;
      font-weight: 600;
      font-size: 1rem;
      cursor: pointer;
      transition: var(--transition);
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 0.5rem;
    }

    .btn:hover {
      background: var(--primary-hover);
      transform: translateY(-2px);
      box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
    }

    .btn:active {
      transform: translateY(0);
      box-shadow: 0 2px 6px rgba(0, 0, 0, 0.1);
    }

    .btn:disabled {
      opacity: 0.6;
      cursor: not-allowed;
      transform: none;
      box-shadow: none;
    }

    /* Back Button */
    .back-btn {
      background: transparent;
      color: var(--primary);
      border: none;
      font-weight: 500;
      padding: 0.5rem;
      margin-bottom: 1rem;
      cursor: pointer;
      display: flex;
      align-items: center;
      gap: 0.5rem;
      font-size: 0.9rem;
      text-decoration: none;
    }

    .back-btn:hover {
      text-decoration: underline;
    }

    /* Extra Links */
    .extra-links {
      text-align: center;
      margin-top: 1.75rem;
      font-size: 0.95rem;
      color: var(--text-secondary);
    }

    .extra-links a {
      color: var(--primary);
      text-decoration: none;
      font-weight: 500;
      transition: var(--transition);
    }

    .extra-links a:hover {
      text-decoration: underline;
      color: var(--primary-hover);
    }

    /* Trust Indicators */
    .trust {
      display: flex;
      justify-content: center;
      align-items: center;
      gap: 1rem;
      margin-top: 2rem;
      padding-top: 1.5rem;
      border-top: 1px solid var(--border);
      color: var(--muted);
      font-size: 0.85rem;
    }

    .trust-icon {
      color: var(--success);
    }

    /* Responsive Adjustments */
    @media (max-width: 480px) {
        .login-card {
            padding: 1.75rem;
        }
        h1#page-title {
            font-size: 1.35rem;
        }
        .subtitle {
            font-size: 0.9rem;
        }
        .login-brand-name {
            font-size: 1.5rem; /* Adjust for small screens if needed */
        }
    }

    @media (prefers-reduced-motion: reduce) {
      *,
      *::before,
      *::after {
        animation-duration: 0.01ms !important;
        animation-iteration-count: 1 !important;
        transition-duration: 0.01ms !important;
      }
    }
  </style>
</head>
<body>
  <!-- Include the standard header which provides the theme toggle and overall nav -->
  <?php require __DIR__.'/../includes/header.php'; ?>

  <main aria-labelledby="page-title">
    <div class="login-card">
      <!-- Company Branding Section -->
      <div class="login-branding">
        <!-- Logo: Simple gear + circle (Copied from header.php) -->
        <svg class="login-logo" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"
             fill="none" stroke="var(--brand)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <circle cx="12" cy="12" r="3"/>
          <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06
                   a1.65 1.65 0 0 0-1.82-.33c-.67.27-1.11.94-1.11 1.68V21a2 2 0 1 1-4 0v-.09
                   c0-.74-.44-1.41-1.11-1.68a1.65 1.65 0 0 0-1.82.33l-.06-.06a2 2 0 1 1-2.83-2.83
                   l.06.06a1.65 1.65 0 0 0 .33-1.82c-.27-.67-.94-1.11-1.68-1.11H3
                   a2 2 0 1 1 0-4h.09c.74 0 1.41-.44 1.68-1.11a1.65 1.65 0 0 0-.33-1.82l-.06-.06
                   a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33c.67-.27 1.11-.94 1.11-1.68V3
                   a2 2 0 1 1 4 0v.09c0 .74.44 1.41 1.11 1.68.66.28 1.35.14 1.82-.33l.06-.06
                   a2 2 0 1 1 2.83 2.83l-.06.06c-.47.47-.61 1.16-.33 1.82.27.67.94 1.11 1.68 1.11H21
                   a2 2 0 1 1 0 4h-.09c-.74 0-1.41.44-1.68 1.11z"/>
        </svg>
        <!-- Brand Name with Gradient (Copied and adapted from header.php) -->
        <a href="<?= e(base_url('index.php')) ?>" class="login-brand-name" style="text-decoration:none;">
            NexusFix
        </a>
      </div>
      <!-- End Company Branding Section -->

      <h1 id="page-title">
        <?php if ($step === 1): ?>
          Sign in
        <?php else: ?>
          Welcome back
        <?php endif; ?>
      </h1>
      
      <?php if ($step === 1): ?>
        <p class="subtitle">Enter your email to continue</p>
      <?php else: ?>
        <p class="subtitle">Enter your password for <strong><?= e($_SESSION['login_email']) ?></strong></p>
      <?php endif; ?>

      <?php if (!empty($errors) && !$showThrottleMessage): ?>
        <div class="alert" role="alert" aria-live="polite">
          <?php foreach ($errors as $msg): ?>
            <div><?= e($msg) ?></div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <?php if ($totalAttempts > 0 && $step === 2): ?>
        <div class="attempt-counter">
          Failed attempts for this account: <strong><?= $totalAttempts ?>/5</strong>
          <?php if ($totalAttempts >= 5): ?>
            <br><small>(Additional security measures will apply after 5 attempts)</small>
          <?php endif; ?>
        </div>
        
        <?php if ($totalAttempts >= 5): ?>
          <div class="progress-bar">
            <div class="progress-fill" style="width: <?= min(100, ($totalAttempts / 10) * 100) ?>%"></div>
          </div>
        <?php endif; ?>
      <?php endif; ?>

      <?php if ($showThrottleMessage): ?>
        <div class="throttle-countdown">
          <div>
            Too many failed attempts. Please try again in <strong><span id="countdown-timer"></span></strong>.
          </div>
          <?php if ($retryAttemptsLeft > 0): ?>
            <div class="throttle-details">
              You have <strong><?= $retryAttemptsLeft ?></strong> attempt<?= $retryAttemptsLeft > 1 ? 's' : '' ?> remaining in this cycle.
            </div>
          <?php else: ?>
            <div class="throttle-details">
              Security lockout activated for <?= format_duration($currentLockoutDuration) ?>.
              <?php if ($nextLockoutDuration > 0): ?>
                Next lockout: <?= format_duration($nextLockoutDuration) ?>.
              <?php endif; ?>
            </div>
          <?php endif; ?>
        </div>
      <?php endif; ?>

      <form method="post" novalidate id="login-form">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

        <?php if ($step === 2): ?>
          <a href="?back=1" class="back-btn">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg>
            Back
          </a>
        <?php endif; ?>

        <?php if ($step === 1): ?>
          <div class="field">
            <label for="email">Email Address</label>
            <input
              type="email"
              id="email"
              name="email"
              value="<?= e($old['email']) ?>"
              required
              autocomplete="username"
              aria-describedby="<?= !empty($errors['email']) ? 'email-error' : '' ?>"
              <?= $showThrottleMessage ? 'disabled' : '' ?>
              autofocus
            >
            <?php if (!empty($errors['email']) && !$showThrottleMessage): ?>
              <div id="email-error" class="field-error"><?= e($errors['email']) ?></div>
            <?php endif; ?>
          </div>
        <?php else: ?>
          <div class="field">
            <label for="password">Password</label>
            <div class="password-field">
              <input
                type="password"
                id="password"
                name="password"
                required
                autocomplete="current-password"
                aria-describedby="<?= !empty($errors['password']) ? 'password-error' : '' ?>"
                <?= $showThrottleMessage ? 'disabled' : '' ?>
                autofocus
              >
              <button type="button" class="toggle-password" id="toggle-password" aria-label="Show password">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
              </button>
            </div>
            <?php if (!empty($errors['password']) && !$showThrottleMessage): ?>
              <div id="password-error" class="field-error"><?= e($errors['password']) ?></div>
            <?php endif; ?>
          </div>
        <?php endif; ?>

        <button type="submit" class="btn" id="submit-btn" 
                <?= $showThrottleMessage ? 'disabled' : '' ?>>
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:none;" id="spinner"><path d="M21 12a9 9 0 1 1-6.219-8.56"></path></svg>
            <?php if ($step === 1): ?>
              Next
            <?php else: ?>
              Sign In
            <?php endif; ?>
        </button>
      </form>

      <div class="extra-links">
        <p>New user? <a href="<?= e(base_url('register.php')) ?>">Create an account</a></p>
        <?php if ($step === 2): ?>
          <p><a href="<?= e(base_url('forgot.php')) ?>">Forgot your password?</a></p>
        <?php endif; ?>
      </div>

      <div class="trust">
        <svg class="trust-icon" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><path d="M9 12l2 2 4-4"></path></svg>
        <span>Secure login with 256-bit encryption</span>
      </div>
    </div>
  </main>

  <script>
    // --- Password Toggle ---
    const passwordInputLoginPage = document.getElementById('password');
    const togglePasswordBtnLoginPage = document.getElementById('toggle-password');

    if (passwordInputLoginPage && togglePasswordBtnLoginPage) {
        togglePasswordBtnLoginPage.addEventListener('click', () => {
            try {
                const type = passwordInputLoginPage.type === 'password' ? 'text' : 'password';
                passwordInputLoginPage.type = type;
                const eyeIcon = togglePasswordBtnLoginPage.querySelector('svg');
                if (eyeIcon) {
                    if (type === 'password') {
                        eyeIcon.innerHTML = '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle>';
                        togglePasswordBtnLoginPage.setAttribute('aria-label', 'Show password');
                    } else {
                        eyeIcon.innerHTML = '<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path><line x1="1" y1="1" x2="23" y2="23"></line>';
                        togglePasswordBtnLoginPage.setAttribute('aria-label', 'Hide password');
                    }
                }
            } catch (error) {
                console.error('Password toggle error:', error);
            }
        });
    }
    // --- End Password Toggle ---

    // --- Auto-focus ---
    document.addEventListener('DOMContentLoaded', () => {
        try {
            const firstErrorInput = document.querySelector('.field-error');
            const emailInput = document.getElementById('email');
            const passwordInput = document.getElementById('password');

            if (firstErrorInput) {
                // Find the input associated with the error
                const errorId = firstErrorInput.id;
                if (errorId) {
                    const inputId = errorId.replace('-error', '');
                    const inputElement = document.getElementById(inputId);
                    if (inputElement) {
                        inputElement.focus();
                        inputElement.select();
                    }
                }
            } else if (emailInput && !emailInput.disabled) {
                emailInput.focus();
            } else if (passwordInput && !passwordInput.disabled) {
                passwordInput.focus();
            }
        } catch (error) {
            console.error('Auto-focus error:', error);
            // Fallback to email input
            const emailInput = document.getElementById('email');
            if (emailInput && !emailInput.disabled) {
                emailInput.focus();
            }
        }
    });
    // --- End Auto-focus ---

    // --- Loading Feedback ---
    const loginForm = document.getElementById('login-form');
    const submitBtn = document.getElementById('submit-btn');
    const spinner = document.getElementById('spinner');

    if (loginForm && submitBtn && spinner) {
        loginForm.addEventListener('submit', () => {
            try {
                submitBtn.disabled = true;
                spinner.style.display = 'inline';
                
                // More robust text node handling
                const textNodes = Array.from(submitBtn.childNodes)
                    .filter(node => node.nodeType === Node.TEXT_NODE);
                
                if (textNodes.length > 0) {
                    textNodes[0].textContent = ' Signing in...';
                } else {
                    // Add text node if not found
                    submitBtn.appendChild(document.createTextNode(' Signing in...'));
                }
            } catch (error) {
                console.error('Loading feedback error:', error);
                // Fallback
                submitBtn.disabled = true;
                if (spinner) spinner.style.display = 'inline';
                submitBtn.lastChild.textContent = ' Signing in...';
            }
        });
    }
    // --- End Loading Feedback ---

    // --- Real-time Countdown ---
    document.addEventListener('DOMContentLoaded', function() {
        const countdownElement = document.getElementById('countdown-timer');
        const submitBtn = document.getElementById('submit-btn');
        const emailInput = document.getElementById('email');
        const passwordInput = document.getElementById('password');
        
        // Get end time from PHP
        <?php if ($showThrottleMessage): ?>
        const endTime = <?= $throttleEndTime ?>;
        <?php else: ?>
        const endTime = 0;
        <?php endif; ?>
        
        if (countdownElement && endTime > 0) {
            function updateCountdown() {
                const now = Math.floor(Date.now() / 1000);
                const remaining = endTime - now;
                
                if (remaining <= 0) {
                    // Time's up - enable form
                    if (submitBtn) submitBtn.disabled = false;
                    if (emailInput) emailInput.disabled = false;
                    if (passwordInput) passwordInput.disabled = false;
                    if (countdownElement) countdownElement.textContent = '0 seconds';
                    return;
                }
                
                // Format display
                let timeString = '';
                const days = Math.floor(remaining / 86400);
                const hours = Math.floor((remaining % 86400) / 3600);
                const minutes = Math.floor((remaining % 3600) / 60);
                const seconds = remaining % 60;
                
                if (days > 0) {
                    timeString += days + 'd ';
                }
                if (hours > 0) {
                    timeString += hours + 'h ';
                }
                if (minutes > 0) {
                    timeString += minutes + 'm ';
                }
                if (seconds >= 0) {
                    timeString += seconds + 's';
                }
                
                // Trim trailing space
                timeString = timeString.trim();
                
                if (countdownElement) countdownElement.textContent = timeString;
            }
            
            // Update immediately
            updateCountdown();
            
            // Update every second
            const countdownInterval = setInterval(updateCountdown, 1000);
            
            // Clean up interval when page unloads
            window.addEventListener('beforeunload', () => {
                clearInterval(countdownInterval);
            });
        }
    });
    // --- End Real-time Countdown ---
  </script>
</body>
</html>