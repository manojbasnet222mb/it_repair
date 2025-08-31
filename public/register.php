<?php
/**
 * Consumer Registration — NexusFix (Ultimate Edition)
 * Inspired by world-class design for a seamless, trustworthy signup experience.
 */

declare(strict_types=1);

session_start();
require_once __DIR__.'/../includes/functions.php';
require_once __DIR__.'/../includes/auth.php';

$errors = [];
$old = ['name'=>'','email'=>'','phone'=>''];

if ($_SERVER['REQUEST_METHOD']==='POST') {
  if (!csrf_verify($_POST['csrf'] ?? '')) {
    $errors['csrf']='Invalid session token';
  }
  $data = [
    'name'=>trim($_POST['name']??''),'email'=>trim($_POST['email']??''),
    'phone'=>trim($_POST['phone']??''),'password'=>$_POST['password']??'',
    'password2'=>$_POST['password2']??'',
  ];
  $old=['name'=>$data['name'],'email'=>$data['email'],'phone'=>$data['phone']];
  if (!$errors && auth_register_customer($data,$errors)) {
    $u = auth_find_user_by_email($data['email']);
    $_SESSION['user']=['id'=>$u['id'],'name'=>$u['name'],'email'=>$u['email'],'role'=>$u['role']];
    redirect(base_url('customer/dashboard.php'));
  }
}

// Page metadata
$title = "Create Account";
$subtitle = "Join NexusFix to manage your devices, track repairs, and get support.";

?>
<!doctype html>
<html lang="en" data-theme="dark"> <!-- Initial theme set to dark -->
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="description" content="Create a NexusFix account to submit repair requests, track status, and manage your devices.">
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

    /* Light Theme */
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

    /* Registration Card */
    .register-card {
      background: linear-gradient(180deg, var(--card), var(--card-2));
      border: 1px solid var(--border);
      border-radius: var(--radius);
      padding: 2.5rem;
      box-shadow: var(--shadow-sm);
      width: 100%;
      max-width: 480px;
      position: relative;
      transition: var(--transition);
    }

    .register-card:hover {
        box-shadow: var(--shadow);
    }

    /* Branding Section */
    .register-branding {
        text-align: center;
        margin-bottom: 2rem;
    }

    .register-logo {
        width: 48px;
        height: 48px;
        margin: 0 auto 12px;
        display: block;
    }

    .register-brand-name {
        font-weight: 700;
        font-size: 1.75rem;
        background: linear-gradient(90deg,#ec4899,#8b5cf6,#3b82f6,#22c55e,#eab308);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        background-clip: text;
        color: transparent;
        text-decoration: none;
        display: block;
    }

    h1#page-title {
      font-size: 1.85rem;
      font-weight: 700;
      margin-bottom: 0.5rem;
      text-align: center;
      color: var(--text);
    }

    .subtitle {
      color: var(--text-secondary);
      font-size: 1rem;
      text-align: center;
      margin-bottom: 2rem;
    }

    /* Form */
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

    .alert ul {
        margin: 0.5rem 0 0 1.2rem;
        padding: 0;
    }

    .field-error {
      color: var(--danger);
      font-size: 0.85rem;
      margin-top: 0.25rem;
      font-weight: 500;
    }

    /* Actions */
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

    .btn.primary {
        background: var(--primary);
        color: white;
    }

    .btn.subtle {
      background: transparent;
      color: var(--muted);
      border: 1px solid var(--field-border);
    }

    .btn.subtle:hover {
      background: rgba(255,255,255,0.06);
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
        .register-card {
            padding: 1.75rem;
        }
        h1#page-title {
            font-size: 1.5rem;
        }
        .subtitle {
            font-size: 0.9rem;
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
  <?php require __DIR__.'/../includes/header.php'; ?>

  <main aria-labelledby="page-title">
    <div class="register-card">
      <!-- Company Branding Section -->
      <div class="register-branding">
        <!-- Logo: Simple gear + circle (Copied from header.php) -->
        <svg class="register-logo" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"
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
        <a href="<?= e(base_url('index.php')) ?>" class="register-brand-name" style="text-decoration:none;">
            NexusFix
        </a>
      </div>
      <!-- End Company Branding Section -->

      <h1 id="page-title">Create Your Account</h1>
      <p class="subtitle">Get started with NexusFix to manage your devices and repairs.</p>

      <?php if (!empty($errors)): ?>
        <div class="alert" role="alert" aria-live="polite">
          <strong>Please fix the following:</strong>
          <ul>
            <?php foreach ($errors as $msg): ?>
              <li><?= e($msg) ?></li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>

      <form method="post" novalidate id="register-form">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

        <div class="field">
          <label for="name">Full Name *</label>
          <input
            type="text"
            id="name"
            name="name"
            value="<?= e($old['name']) ?>"
            required
            placeholder="e.g. Alex Johnson"
            autocomplete="name"
            aria-describedby="<?= !empty($errors['name']) ? 'name-error' : '' ?>"
          >
          <?php if (!empty($errors['name'])): ?>
            <div id="name-error" class="field-error"><?= e($errors['name']) ?></div>
          <?php endif; ?>
        </div>

        <div class="field">
          <label for="email">Email Address *</label>
          <input
            type="email"
            id="email"
            name="email"
            value="<?= e($old['email']) ?>"
            required
            placeholder="your.email@example.com"
            autocomplete="email"
            aria-describedby="<?= !empty($errors['email']) ? 'email-error' : '' ?>"
          >
          <?php if (!empty($errors['email'])): ?>
            <div id="email-error" class="field-error"><?= e($errors['email']) ?></div>
          <?php endif; ?>
        </div>

        <div class="field">
          <label for="phone">Phone Number (Nepal) *</label>
          <input
            type="tel"
            id="phone"
            name="phone"
            value="<?= e($old['phone']) ?>"
            required
            placeholder="e.g. 98XXXXXXXX"
            autocomplete="tel"
            maxlength="10"
            pattern="[9][0-9]{9}"
            title="Nepal phone number (10 digits starting with 9)"
          >
          <?php if (!empty($errors['phone'])): ?>
            <div id="phone-error" class="field-error"><?= e($errors['phone']) ?></div>
          <?php endif; ?>
          <div style="font-size: 0.8rem; color: var(--text-secondary); margin-top: 0.25rem;">
            Enter 10-digit Nepal phone number (e.g., 98XXXXXXXX)
          </div>
        </div>

        <div class="field">
          <label for="password">Password *</label>
          <div class="password-field">
            <input
              type="password"
              id="password"
              name="password"
              required
              placeholder="••••••••"
              autocomplete="new-password"
              aria-describedby="<?= !empty($errors['password']) ? 'password-error' : '' ?>"
            >
            <button type="button" class="toggle-password" id="toggle-password" aria-label="Show password">
              <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
            </button>
          </div>
          <?php if (!empty($errors['password'])): ?>
            <div id="password-error" class="field-error"><?= e($errors['password']) ?></div>
          <?php endif; ?>
        </div>

        <div class="field">
          <label for="password2">Confirm Password *</label>
          <div class="password-field">
            <input
              type="password"
              id="password2"
              name="password2"
              required
              placeholder="••••••••"
              autocomplete="new-password"
              aria-describedby="<?= !empty($errors['password2']) ? 'password2-error' : '' ?>"
            >
            <button type="button" class="toggle-password" id="toggle-password2" aria-label="Show password">
              <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
            </button>
          </div>
          <?php if (!empty($errors['password2'])): ?>
            <div id="password2-error" class="field-error"><?= e($errors['password2']) ?></div>
          <?php endif; ?>
        </div>

        <button type="submit" class="btn primary" id="submit-btn">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:none;" id="spinner"><path d="M21 12a9 9 0 1 1-6.219-8.56"></path></svg>
            Create Account
        </button>
      </form>

      <div class="extra-links">
        <p>Already have an account? <a href="<?= e(base_url('login.php')) ?>">Sign in here</a></p>
      </div>

      <!-- Trust Indicators -->
      <div class="trust">
        <svg class="trust-icon" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><path d="M9 12l2 2 4-4"></path></svg>
        <span>Your data is encrypted and secure</span>
      </div>
    </div>
  </main>

  <script>
    // --- Password Toggle ---
    function setupPasswordToggle(inputId, toggleId) {
        const passwordInput = document.getElementById(inputId);
        const togglePasswordBtn = document.getElementById(toggleId);

        if (passwordInput && togglePasswordBtn) {
            togglePasswordBtn.addEventListener('click', () => {
                const type = passwordInput.type === 'password' ? 'text' : 'password';
                passwordInput.type = type;
                const eyeIcon = togglePasswordBtn.querySelector('svg');
                if (eyeIcon) {
                    if (type === 'password') {
                        eyeIcon.innerHTML = '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle>';
                        togglePasswordBtn.setAttribute('aria-label', 'Show password');
                    } else {
                        eyeIcon.innerHTML = '<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path><line x1="1" y1="1" x2="23" y2="23"></line>';
                        togglePasswordBtn.setAttribute('aria-label', 'Hide password');
                    }
                }
            });
        }
    }

    // Setup both password toggles
    document.addEventListener('DOMContentLoaded', () => {
        setupPasswordToggle('password', 'toggle-password');
        setupPasswordToggle('password2', 'toggle-password2');
    });
    // --- End Password Toggle ---

    // --- Auto-focus ---
    document.addEventListener('DOMContentLoaded', () => {
        const firstErrorInput = document.querySelector('.field-error');
        const nameInput = document.getElementById('name');

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
        } else if (nameInput) {
            nameInput.focus();
        }
    });
    // --- End Auto-focus ---

    // --- Loading Feedback ---
    const registerForm = document.getElementById('register-form');
    const submitBtn = document.getElementById('submit-btn');
    const spinner = document.getElementById('spinner');

    if (registerForm && submitBtn && spinner) {
        registerForm.addEventListener('submit', () => {
            submitBtn.disabled = true;
            spinner.style.display = 'inline';
            
            // More robust text node handling
            const textNodes = Array.from(submitBtn.childNodes)
                .filter(node => node.nodeType === Node.TEXT_NODE);
            
            if (textNodes.length > 0) {
                textNodes[0].textContent = ' Creating account...';
            } else {
                // Add text node if not found
                submitBtn.appendChild(document.createTextNode(' Creating account...'));
            }
        });
    }
    // --- End Loading Feedback ---

    // --- Phone Number Formatting ---
    document.addEventListener('DOMContentLoaded', () => {
        const phoneInput = document.getElementById('phone');
        if (phoneInput) {
            phoneInput.addEventListener('input', function(e) {
                // Remove any non-digit characters
                let value = e.target.value.replace(/\D/g, '');
                
                // Limit to 10 digits
                if (value.length > 10) {
                    value = value.substring(0, 10);
                }
                
                // Ensure it starts with 9
                if (value.length > 0 && value[0] !== '9') {
                    value = '9' + value.substring(0, 9);
                }
                
                e.target.value = value;
            });
        }
    });
    // --- End Phone Number Formatting ---
  </script>
</body>
</html>