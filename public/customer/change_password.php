<?php
/**
 * Change Password Page - NexusFix
 * Features:
 * - Secure password change with validation
 * - Real-time strength meter
 * - Dark/light mode support
 */

declare(strict_types=1);
require_once __DIR__.'/../../includes/bootstrap.php';
require_role('customer');

$u = $_SESSION['user'];
$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $current_password = $_POST['current_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    
    // Validation
    if (empty($current_password)) {
        $errors[] = 'Current password is required.';
    }
    
    if (empty($new_password)) {
        $errors[] = 'New password is required.';
    } elseif (strlen($new_password) < 8) {
        $errors[] = 'New password must be at least 8 characters long.';
    } elseif (!preg_match('/[A-Z]/', $new_password)) {
        $errors[] = 'New password must contain at least one uppercase letter.';
    } elseif (!preg_match('/[a-z]/', $new_password)) {
        $errors[] = 'New password must contain at least one lowercase letter.';
    } elseif (!preg_match('/[0-9]/', $new_password)) {
        $errors[] = 'New password must contain at least one number.';
    } elseif (!preg_match('/[^A-Za-z0-9]/', $new_password)) {
        $errors[] = 'New password must contain at least one special character.';
    }
    
    if (empty($confirm_password)) {
        $errors[] = 'Please confirm your new password.';
    } elseif ($new_password !== $confirm_password) {
        $errors[] = 'New passwords do not match.';
    }
    
    if (empty($errors)) {
        try {
            $pdo = db();
            
            // Verify current password (using password_hash column)
            $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE id = ?");
            $stmt->execute([$u['id']]);
            $user = $stmt->fetch();
            
            if (!$user) {
                $errors[] = 'User not found.';
            } elseif (!password_verify($current_password, $user['password_hash'])) {
                $errors[] = 'Current password is incorrect.';
            } else {
                // Update password (using password_hash column)
                $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
                
                if ($stmt->execute([$hashed_password, $u['id']])) {
                    $success = 'Password changed successfully!';
                    // Clear form fields for security
                    $current_password = $new_password = $confirm_password = '';
                } else {
                    $errors[] = 'Failed to update password. Please try again.';
                }
            }
        } catch (PDOException $e) {
            error_log("Database error in change_password.php: " . $e->getMessage());
            $errors[] = 'Database error occurred. Please try again later.';
        } catch (Exception $e) {
            error_log("General error in change_password.php: " . $e->getMessage());
            $errors[] = 'An error occurred. Please try again.';
        }
    }
}
?>
<!doctype html>
<html lang="en" data-theme="dark">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="description" content="Securely change your NexusFix account password">
  <title>Change Password — NexusFix</title>
  <link rel="stylesheet" href="../../assets/css/styles.css">
  <style>
    :root {
      --bg: #0b0c0f;
      --card: #101218;
      --card-2: #121622;
      --text: #e8eaf0;
      --muted: #a6adbb;
      --primary: #60a5fa;
      --primary-hover: #4f9cf9;
      --accent: #6ee7b7;
      --warning: #fbbf24;
      --danger: #f87171;
      --success: #34d399;
      --border: #1f2430;
      --field-border: #2a3242;
      --ring: rgba(96,165,250,.45);
      --shadow: 0 10px 30px rgba(0,0,0,.35);
      --shadow-sm: 0 4px 12px rgba(0,0,0,.18);
      --radius: 16px;
      --transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
    }

    /* Light Theme */
    :root[data-theme="light"] {
      --bg: #f7f8fb;
      --card: #ffffff;
      --card-2: #f9fafb;
      --text: #0b0c0f;
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
      line-height: 1.5;
      margin: 0;
      padding: 0;
      transition: background 0.3s ease;
    }

    main {
      min-height: 60vh;
      padding: clamp(16px, 3vw, 32px);
      max-width: 800px;
      margin: 0 auto;
    }

    .page-heading {
      margin-bottom: 24px;
    }

    .page-heading h2 {
      font-size: clamp(1.5rem, 1rem + 1.5vw, 2rem);
      font-weight: 600;
      margin: 0 0 8px;
    }

    .page-heading p {
      color: var(--muted);
      margin: 0;
    }

    .card {
      background: linear-gradient(180deg, var(--card), var(--card-2));
      border: 1px solid var(--border);
      border-radius: var(--radius);
      padding: clamp(24px, 3vw, 32px);
      box-shadow: var(--shadow-sm);
      transition: var(--transition);
      margin-bottom: 24px;
    }

    .form-group {
      margin-bottom: 20px;
    }

    .form-group label {
      display: block;
      margin-bottom: 8px;
      font-weight: 500;
      color: var(--text);
    }

    .form-control {
      width: 100%;
      padding: 12px 16px;
      border-radius: 12px;
      border: 1px solid var(--field-border);
      background: var(--card);
      color: var(--text);
      font-size: 1rem;
      transition: var(--transition);
      box-sizing: border-box;
    }

    .form-control:focus {
      outline: none;
      border-color: var(--primary);
      box-shadow: 0 0 0 3px rgba(96, 165, 250, 0.2);
    }

    .password-container {
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
      cursor: pointer;
      padding: 4px;
    }

    .strength-meter {
      height: 5px;
      background: var(--field-border);
      border-radius: 3px;
      margin-top: 8px;
      overflow: hidden;
    }

    .strength-fill {
      height: 100%;
      width: 0;
      border-radius: 3px;
      transition: width 0.3s, background 0.3s;
    }

    .strength-text {
      font-size: 0.85rem;
      margin-top: 6px;
      color: var(--muted);
    }

    .match-indicator {
      display: flex;
      align-items: center;
      gap: 8px;
      margin-top: 8px;
      font-size: 0.85rem;
    }

    .match-status {
      width: 12px;
      height: 12px;
      border-radius: 50%;
      background: var(--field-border);
    }

    .match-status.match {
      background: var(--success);
    }

    .match-status.nomatch {
      background: var(--danger);
    }

    .btn {
      display: inline-flex;
      align-items: center;
      gap: 0.5rem;
      padding: 0.75rem 1.5rem;
      border-radius: 12px;
      text-decoration: none;
      font-weight: 600;
      font-size: 1rem;
      transition: var(--transition);
      border: 1px solid transparent;
      cursor: pointer;
      justify-content: center;
    }

    .btn.primary {
      background: var(--primary);
      color: white;
      width: 100%;
    }

    .btn.primary:hover {
      background: var(--primary-hover);
      transform: translateY(-1px);
    }

    .btn:focus-visible {
      outline: 3px solid var(--ring);
      outline-offset: 3px;
    }

    .alert {
      padding: 16px;
      border-radius: 12px;
      margin-bottom: 24px;
      font-weight: 500;
    }

    .alert.error {
      background: rgba(248, 113, 113, 0.15);
      border: 1px solid rgba(248, 113, 113, 0.3);
      color: #f87171;
    }

    .alert.success {
      background: rgba(52, 211, 153, 0.15);
      border: 1px solid rgba(52, 211, 153, 0.3);
      color: #34d399;
    }

    .alert ul {
      margin: 8px 0 0;
      padding-left: 20px;
    }

    .alert li {
      margin-bottom: 4px;
    }

    .requirements {
      background: rgba(96, 165, 250, 0.1);
      border: 1px solid rgba(96, 165, 250, 0.2);
      border-radius: 12px;
      padding: 16px;
      margin-top: 24px;
    }

    .requirements h3 {
      margin-top: 0;
      font-size: 1.1rem;
    }

    .requirements ul {
      padding-left: 20px;
      margin: 12px 0 0;
    }

    .requirements li {
      margin-bottom: 8px;
      color: var(--muted);
    }

    .requirements li.valid {
      color: var(--success);
    }

    .requirements li::marker {
      color: var(--muted);
    }

    .requirements li.valid::marker {
      color: var(--success);
    }

    @media (max-width: 600px) {
      .card {
        padding: 20px;
      }
    }
  </style>
</head>
<body>
  <?php require __DIR__.'/../../includes/header.php'; ?>

  <main aria-labelledby="pg-title">
    <header class="page-heading">
      <h2 id="pg-title">Change Password</h2>
      <p>Update your account password for enhanced security</p>
    </header>

    <?php if (!empty($errors)): ?>
      <div class="alert error" role="alert">
        <strong>There were issues with your submission:</strong>
        <ul>
          <?php foreach ($errors as $error): ?>
            <li><?= e($error) ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>

    <?php if ($success): ?>
      <div class="alert success" role="alert">
        <strong>Success!</strong> <?= e($success) ?>
      </div>
    <?php endif; ?>

    <div class="card">
      <form method="post" id="passwordForm">
        <div class="form-group">
          <label for="current_password">Current Password</label>
          <div class="password-container">
            <input 
              type="password" 
              id="current_password" 
              name="current_password" 
              class="form-control" 
              required
              autocomplete="current-password"
              value="<?= isset($current_password) ? e($current_password) : '' ?>"
            >
            <button type="button" class="toggle-password" aria-label="Show password">
              <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                <circle cx="12" cy="12" r="3"></circle>
              </svg>
            </button>
          </div>
        </div>

        <div class="form-group">
          <label for="new_password">New Password</label>
          <div class="password-container">
            <input 
              type="password" 
              id="new_password" 
              name="new_password" 
              class="form-control" 
              required
              autocomplete="new-password"
              minlength="8"
              value="<?= isset($new_password) ? e($new_password) : '' ?>"
            >
            <button type="button" class="toggle-password" aria-label="Show password">
              <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                <circle cx="12" cy="12" r="3"></circle>
              </svg>
            </button>
          </div>
          <div class="strength-meter">
            <div class="strength-fill" id="strengthFill"></div>
          </div>
          <div class="strength-text" id="strengthText">Password strength: None</div>
        </div>

        <div class="form-group">
          <label for="confirm_password">Confirm New Password</label>
          <div class="password-container">
            <input 
              type="password" 
              id="confirm_password" 
              name="confirm_password" 
              class="form-control" 
              required
              autocomplete="new-password"
              value="<?= isset($confirm_password) ? e($confirm_password) : '' ?>"
            >
            <button type="button" class="toggle-password" aria-label="Show password">
              <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                <circle cx="12" cy="12" r="3"></circle>
              </svg>
            </button>
          </div>
          <div class="strength-meter">
            <div class="strength-fill" id="confirmStrengthFill"></div>
          </div>
          <div class="match-indicator">
            <div class="match-status" id="matchStatus"></div>
            <div class="strength-text" id="matchText">Passwords match: Not checked</div>
          </div>
        </div>

        <button type="submit" class="btn primary">Update Password</button>
      </form>
    </div>

    <div class="requirements">
      <h3>Password Requirements</h3>
      <ul>
        <li id="req-length">At least 8 characters</li>
        <li id="req-upper">At least one uppercase letter</li>
        <li id="req-lower">At least one lowercase letter</li>
        <li id="req-number">At least one number</li>
        <li id="req-special">At least one special character</li>
      </ul>
    </div>
  </main>

  <script>
    document.addEventListener('DOMContentLoaded', function() {
      // Password visibility toggles
      const toggleButtons = document.querySelectorAll('.toggle-password');
      toggleButtons.forEach(button => {
        button.addEventListener('click', function() {
          const input = this.previousElementSibling;
          const type = input.getAttribute('type') === 'password' ? 'text' : 'password';
          input.setAttribute('type', type);
          
          // Update icon
          const icon = this.querySelector('svg');
          if (type === 'password') {
            icon.innerHTML = '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle>';
          } else {
            icon.innerHTML = '<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path><line x1="1" y1="1" x2="23" y2="23"></line>';
          }
        });
      });

      // Password strength meter
      const newPasswordInput = document.getElementById('new_password');
      const strengthFill = document.getElementById('strengthFill');
      const strengthText = document.getElementById('strengthText');
      const requirements = {
        length: document.getElementById('req-length'),
        upper: document.getElementById('req-upper'),
        lower: document.getElementById('req-lower'),
        number: document.getElementById('req-number'),
        special: document.getElementById('req-special')
      };

      newPasswordInput.addEventListener('input', function() {
        const password = this.value;
        let strength = 0;
        let feedback = '';
        let color = '';

        // Reset requirements
        Object.values(requirements).forEach(req => req.classList.remove('valid'));

        // Check requirements
        if (password.length >= 8) {
          strength++;
          requirements.length.classList.add('valid');
        }
        if (/[A-Z]/.test(password)) {
          strength++;
          requirements.upper.classList.add('valid');
        }
        if (/[a-z]/.test(password)) {
          strength++;
          requirements.lower.classList.add('valid');
        }
        if (/[0-9]/.test(password)) {
          strength++;
          requirements.number.classList.add('valid');
        }
        if (/[^A-Za-z0-9]/.test(password)) {
          strength++;
          requirements.special.classList.add('valid');
        }

        // Update strength meter
        const percentage = (strength / 5) * 100;
        strengthFill.style.width = percentage + '%';

        // Set strength text and color
        if (password.length === 0) {
          feedback = 'Password strength: None';
          color = '#2a3242';
        } else if (strength < 3) {
          feedback = 'Password strength: Weak';
          color = '#f87171';
        } else if (strength < 5) {
          feedback = 'Password strength: Medium';
          color = '#fbbf24';
        } else {
          feedback = 'Password strength: Strong';
          color = '#34d399';
        }

        strengthFill.style.background = color;
        strengthText.textContent = feedback;
        
        // Update confirm password strength
        updateConfirmStrength();
      });

      // Confirm password matching
      const confirmPasswordInput = document.getElementById('confirm_password');
      const confirmStrengthFill = document.getElementById('confirmStrengthFill');
      const matchStatus = document.getElementById('matchStatus');
      const matchText = document.getElementById('matchText');
      
      function updateConfirmStrength() {
        const newPassword = newPasswordInput.value;
        const confirmPassword = confirmPasswordInput.value;
        
        if (confirmPassword === '') {
          confirmStrengthFill.style.width = '0%';
          confirmStrengthFill.style.background = '#2a3242';
          matchStatus.className = 'match-status';
          matchText.textContent = 'Passwords match: Not checked';
        } else if (confirmPassword === newPassword && newPassword !== '') {
          confirmStrengthFill.style.width = '100%';
          confirmStrengthFill.style.background = '#34d399';
          matchStatus.className = 'match-status match';
          matchText.textContent = 'Passwords match: Yes';
        } else {
          confirmStrengthFill.style.width = '100%';
          confirmStrengthFill.style.background = '#f87171';
          matchStatus.className = 'match-status nomatch';
          matchText.textContent = 'Passwords match: No';
        }
      }
      
      confirmPasswordInput.addEventListener('input', updateConfirmStrength);
    });
  </script>
</body>
</html>