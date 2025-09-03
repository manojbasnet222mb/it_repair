<?php
// /htdocs/it_repair/includes/header.php

// --- Calculate Live Unread Notification Count for the Header ---
$unread_count_header = 0;
if (!empty($_SESSION['user']) && $_SESSION['user']['role'] === 'customer') {
    try {
        $pdo_header = db(); // Assuming db() function is available via bootstrap
        $stmt_unread_header = $pdo_header->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
        $stmt_unread_header->execute([$_SESSION['user']['id']]);
        $unread_count_header = (int)$stmt_unread_header->fetchColumn();
        // Update session variable for potential use elsewhere
        $_SESSION['unread_notifications_count'] = $unread_count_header;
    } catch (Exception $e) {
        error_log("Header notification count error: " . $e->getMessage());
        // Keep $unread_count_header as 0 on error
    }
}
// For non-customers or errors, $unread_count_header remains 0

// --- Theme Handling Logic (Improved) ---
// Let JavaScript be the ultimate authority using localStorage
// Set a default theme in PHP just in case JS fails or is slow
$initial_theme = 'dark'; // Default fallback
// Check localStorage via JS on page load (more reliable than PHP cookies/session for client-side toggle)

// --- Define base path for checking file existence ---
// This ensures we correctly build paths for file system operations in the header
define('HEADER_BASE_UPLOAD_PATH', rtrim(realpath($_SERVER['DOCUMENT_ROOT']), DIRECTORY_SEPARATOR));
?>
<!DOCTYPE html>
<!-- The data-theme will be set by JavaScript below, this is just a fallback -->
<html lang="en" data-theme="<?= e($initial_theme) ?>">
<head>
  <style>
    /* Define CSS variables for header */
    :root[data-theme="dark"] {
      --card: #101218;
      --line: #1f2430;
      --text: #e8eaf0;
      --brand: #60a5fa;
      --muted: #a6adbb;
      --field-border: #2a3242;
      --dropdown-bg: #1a1d26;
      --hover-bg: rgba(96, 165, 250, 0.1);
      --success: #34d399;
    }
    :root[data-theme="light"] {
      --card: #ffffff;
      --line: #e5e7eb;
      --text: #0b0c0f;
      --brand: #3b82f6;
      --muted: #5b6172;
      --field-border: #cbd5e1;
      --dropdown-bg: #f8fafc;
      --hover-bg: rgba(59, 130, 246, 0.1);
      --success: #10b981;
    }

    /* --- Theme Toggle Styles --- */
    .theme-toggle {
      display: flex;
      align-items: center;
      gap: 0.5rem;
      font-size: 0.9rem;
      color: var(--muted);
    }

    .toggle-switch {
      position: relative;
      display: inline-block;
      width: 44px;
      height: 24px;
    }

    .toggle-switch input {
      opacity: 0;
      width: 0;
      height: 0;
    }

    .slider {
      position: absolute;
      cursor: pointer;
      top: 0;
      left: 0;
      right: 0;
      bottom: 0;
      background: var(--field-border);
      transition: .3s;
      border-radius: 24px;
    }

    .slider:before {
      position: absolute;
      content: "";
      height: 18px;
      width: 18px;
      left: 3px;
      bottom: 3px;
      background: white;
      transition: .3s;
      border-radius: 50%;
    }

    input:checked + .slider {
      background: var(--brand);
    }

    input:checked + .slider:before {
      transform: translateX(20px);
    }
    /* --- End Theme Toggle Styles --- */

    /* --- Dropdown Styles --- */
    .dropdown {
      position: relative;
      display: inline-block;
    }

    .dropdown-content {
      display: none;
      position: absolute;
      right: 0;
      background: var(--dropdown-bg);
      min-width: 220px;
      box-shadow: 0 10px 25px rgba(0,0,0,0.25);
      border-radius: 12px;
      z-index: 1000;
      padding: 8px 0;
      border: 1px solid var(--line);
      margin-top: 8px;
      animation: fadeIn 0.2s ease forwards;
    }
    /* Show the dropdown menu when the parent has the 'show' class */
    .dropdown.show .dropdown-content {
      display: block;
    }

    @keyframes fadeIn {
      from { opacity: 0; transform: translateY(-10px); }
      to { opacity: 1; transform: translateY(0); }
    }

    .dropdown-content a {
      color: var(--text);
      padding: 12px 16px;
      text-decoration: none;
      display: flex;
      align-items: center;
      gap: 12px;
      font-size: 0.95rem;
      transition: all 0.2s;
    }

    .dropdown-content a:hover {
      background: var(--hover-bg);
    }

    .dropdown-content .divider {
      height: 1px;
      background: var(--line);
      margin: 8px 0;
    }

    .user-avatar-header {
      width: 36px;
      height: 36px;
      border-radius: 50%;
      /* If showing image, object-fit is useful */
      /* object-fit: cover; */
      /* If showing initials, these styles apply */
      display: flex;
      align-items: center;
      justify-content: center;
      font-weight: 600;
      color: white;
      font-size: 0.9rem;
      border: 2px solid rgba(96, 165, 250, 0.3);
      transition: transform 0.2s;
      cursor: pointer;
    }
    /* Specific style for image avatar */
    .user-avatar-header img {
        width: 100%;
        height: 100%;
        border-radius: 50%;
        object-fit: cover;
    }
    /* Specific style for initials avatar */
    .user-avatar-header.initials {
        background: linear-gradient(135deg, var(--brand), #818cf8);
    }

    .user-avatar-header:hover {
      transform: scale(1.05);
    }

    .user-info {
      display: flex;
      flex-direction: column;
      margin-left: 12px;
      line-height: 1.4;
    }

    .user-name-header {
      font-weight: 600;
      font-size: 0.95rem;
    }

    .user-role {
      font-size: 0.8rem;
      color: var(--muted);
    }

    .user-menu-trigger {
      display: flex;
      align-items: center;
      cursor: pointer;
      padding: 6px 12px;
      border-radius: 50px;
      transition: background 0.2s;
      border: 1px solid transparent;
    }

    .user-menu-trigger:hover {
      background: var(--hover-bg);
      border-color: var(--field-border);
    }

    /* Mobile menu styles */
    .mobile-menu {
      display: none;
      position: fixed;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      background: var(--card);
      z-index: 1000;
      padding: 20px;
      flex-direction: column;
    }

    .mobile-menu-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 30px;
    }

    .close-menu {
      background: none;
      border: none;
      font-size: 1.5rem;
      color: var(--text);
      cursor: pointer;
    }

    .mobile-nav {
      display: flex;
      flex-direction: column;
      gap: 15px;
    }

    .mobile-nav a {
      color: var(--text);
      text-decoration: none;
      font-size: 1.1rem;
      padding: 10px 0;
      border-bottom: 1px solid var(--line);
    }

    .mobile-auth {
      margin-top: auto;
      display: flex;
      flex-direction: column;
      gap: 10px;
    }

    .mobile-auth a {
      padding: 12px;
      border-radius: 8px;
      text-align: center;
      font-weight: 500;
    }

    .mobile-auth .btn-login {
      border: 1px solid var(--brand);
      color: var(--brand);
    }

    .mobile-auth .btn-register {
      background: var(--brand);
      color: white;
    }

    @media (max-width: 768px) {
      .nav, .auth .hello, .auth .btn.subtle {
        display: none;
      }

      .menu-toggle {
        display: block !important;
      }

      .mobile-menu.active {
        display: flex;
      }
    }
  </style>
</head>
<body>

<header class="site-header" style="
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 12px 20px;
  border-bottom: 1px solid var(--line);
  background: var(--card);
  position: sticky;
  top: 0;
  z-index: 100;
  transition: background 0.3s ease;
">
  <div class="brand">
    <?php if (!empty($_SESSION['user']) && $_SESSION['user']['role'] === 'staff'): ?>
      <a href="<?= e(base_url('staff/dashboard.php')) ?>" style="display:flex; align-items:center; gap:8px; text-decoration:none;">
    <?php elseif (!empty($_SESSION['user']) && $_SESSION['user']['role'] === 'customer'): ?>
      <a href="<?= e(base_url('customer/dashboard.php')) ?>" style="display:flex; align-items:center; gap:8px; text-decoration:none;">
    <?php else: ?>
      <a href="<?= e(base_url('index.php')) ?>" style="display:flex; align-items:center; gap:8px; text-decoration:none;">
    <?php endif; ?>
        <!-- Logo: Simple gear + circle -->
        <svg class="logo" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"
             fill="none" stroke="var(--brand)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
             style="width:28px; height:28px;">
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
        <!-- Brand Name with Gradient (works in all browsers) -->
        <span class="brand-name" style="
          font-weight:700; font-size:1.35rem;
          background:linear-gradient(90deg,#ec4899,#8b5cf6,#3b82f6,#22c55e,#eab308);
          -webkit-background-clip:text;
          -webkit-text-fill-color:transparent;
          background-clip:text;
          color:transparent;
        ">NexusFix</span>
      </a>
  </div>

  <nav class="nav" style="display:flex; gap:1.5rem; font-size:0.95rem;">
    <?php if (!empty($_SESSION['user']) && $_SESSION['user']['role'] === 'customer'): ?>
      <a href="<?= e(base_url('customer/dashboard.php')) ?>" style="color:var(--brand); text-decoration:none;">Dashboard</a>
      <a href="<?= e(base_url('customer/request_new.php')) ?>" style="color:var(--muted); text-decoration:none;">New Request</a>
      <a href="<?= e(base_url('customer/requests.php')) ?>" style="color:var(--muted); text-decoration:none;">My Requests</a>
      <a href="<?= e(base_url('support/contact.php')) ?>" style="color:var(--muted); text-decoration:none;">Contact Support</a>
      <a href="<?= e(base_url('customer/notifications.php')) ?>" style="color:var(--muted); text-decoration:none; position:relative;">
          Notifications
          <?php if ($unread_count_header > 0): ?>
            <span style="
              position: absolute;
              top: -5px;
              right: -8px;
              background: #f87171;
              color: white;
              border-radius: 50%;
              width: 18px;
              height: 18px;
              font-size: 0.7rem;
              display: flex;
              align-items: center;
              justify-content: center;
              font-weight: bold;
            " aria-label="<?= e((string)$unread_count_header) ?> unread notifications">
              <?= e((string)min($unread_count_header, 99)) ?>
              <?php if ($unread_count_header > 99): ?>+<?php endif; ?>
            </span>
          <?php endif; ?>
      </a>
    <?php elseif (!empty($_SESSION['user']) && ($_SESSION['user']['role'] === 'staff' || $_SESSION['user']['role'] === 'admin')): ?>
      <a href="<?= e(base_url('staff/dashboard.php')) ?>" style="color:var(--brand); text-decoration:none;">Dashboard</a>
      <a href="<?= e(base_url('staff/request_new.php')) ?>" style="color:var(--muted); text-decoration:none;">New Ticket</a>
      <a href="<?= e(base_url('staff/registration.php')) ?>" style="color:var(--muted); text-decoration:none;">Registration</a>
      <a href="<?= e(base_url('staff/repair.php')) ?>" style="color:var(--muted); text-decoration:none;">Repair</a>
      <a href="<?= e(base_url('staff/billing.php')) ?>" style="color:var(--muted); text-decoration:none;">Billing</a>
      <a href="<?= e(base_url('staff/shipping.php')) ?>" style="color:var(--muted); text-decoration:none;">Shipping</a>
    <?php else: ?>
      <a href="<?= e(base_url('index.php#services')) ?>" style="color:var(--muted); text-decoration:none;">Services</a>
      <a href="<?= e(base_url('index.php#how')) ?>" style="color:var(--muted); text-decoration:none;">How it works</a>
      <a href="<?= e(base_url('index.php#trust')) ?>" style="color:var(--muted); text-decoration:none;">Why us</a>
      <a href="<?= e(base_url('index.php#contact')) ?>" style="color:var(--muted); text-decoration:none;">Contact</a>
    <?php endif; ?>
  </nav>

  <div class="auth" style="display:flex; gap:12px; align-items:center;">
    <?php if (!empty($_SESSION['user'])): ?>
      <div class="dropdown" id="userDropdown">
        <div class="user-menu-trigger">
            <?php
            // Check if profile picture exists using the correct path
            $hasProfilePictureHeader = !empty($_SESSION['user']['profile_picture']) &&
                                       file_exists(HEADER_BASE_UPLOAD_PATH . DIRECTORY_SEPARATOR . $_SESSION['user']['profile_picture']);
            if ($hasProfilePictureHeader):
            ?>
                <!-- Display profile picture -->
                <div class="user-avatar-header">
                    <img src="<?= e('/' . $_SESSION['user']['profile_picture']) ?>" alt="Profile Picture">
                </div>
            <?php else: ?>
                <!-- Display initials avatar -->
                <div class="user-avatar-header initials">
                <?php
                    $name = $_SESSION['user']['name'];
                    $initials = '';
                    $words = explode(' ', $name);
                    foreach (array_slice($words, 0, 2) as $word) {
                        $initials .= strtoupper($word[0]);
                    }
                    echo $initials;
                ?>
                </div>
            <?php endif; ?>
        </div>
        <div class="dropdown-content">
          <div style="padding: 12px 16px; border-bottom: 1px solid var(--line); display: flex; align-items: center; gap: 12px;">
            <?php if ($hasProfilePictureHeader): ?>
                <!-- Display profile picture in dropdown header -->
                <div class="user-avatar-header">
                    <img src="<?= e('/' . $_SESSION['user']['profile_picture']) ?>" alt="Profile Picture">
                </div>
            <?php else: ?>
                <!-- Display initials avatar in dropdown header -->
                <div class="user-avatar-header initials">
                <?php
                    $name = $_SESSION['user']['name'];
                    $initials = '';
                    $words = explode(' ', $name);
                    foreach (array_slice($words, 0, 2) as $word) {
                        $initials .= strtoupper($word[0]);
                    }
                    echo $initials;
                ?>
                </div>
            <?php endif; ?>
            <div class="user-info">
              <div class="user-name-header"><?= e($_SESSION['user']['name']) ?></div>
              <div class="user-role"><?= ucfirst(e($_SESSION['user']['role'])) ?></div>
            </div>
          </div>
          <!-- Dynamically link to staff or customer profile -->
          <?php if ($_SESSION['user']['role'] === 'staff' || $_SESSION['user']['role'] === 'admin'): ?>
            <a href="<?= e(base_url('staff/profile.php')) ?>">
          <?php else: // customer ?>
            <a href="<?= e(base_url('customer/profile.php')) ?>">
          <?php endif; ?>
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
              <circle cx="12" cy="7" r="4"></circle>
            </svg>
            Edit Profile
          </a>
          <!-- Dynamically link to staff or customer change password -->
          <?php if ($_SESSION['user']['role'] === 'staff' || $_SESSION['user']['role'] === 'admin'): ?>
            <a href="<?= e(base_url('staff/change_password.php')) ?>">
          <?php else: // customer ?>
            <a href="<?= e(base_url('customer/change_password.php')) ?>">
          <?php endif; ?>
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
              <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
            </svg>
            Change Password
          </a>
          <div class="divider"></div>
          <a href="<?= e(base_url('logout.php')) ?>" style="color: #f87171;">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path>
              <polyline points="16 17 21 12 16 7"></polyline>
              <line x1="21" y1="12" x2="9" y2="12"></line>
            </svg>
            Logout
          </a>
        </div>
      </div>
    <?php else: ?>
      <a class="btn ghost" href="<?= e(base_url('login.php')) ?>" style="padding:6px 12px; border-radius:8px; color:var(--brand); border:1px solid var(--brand); text-decoration:none; font-size:0.9rem;">Login</a>
      <a class="btn glow" href="<?= e(base_url('register.php')) ?>" style="padding:6px 12px; border-radius:8px; background:var(--brand); color:white; text-decoration:none; font-size:0.9rem; font-weight:500;">Register</a>
    <?php endif; ?>

    <!-- ADD THE THEME TOGGLE SWITCH HERE -->
    <div class="theme-toggle">
        <span style="user-select: none;">Dark</span>
        <label class="toggle-switch">
            <input type="checkbox" id="theme-toggle">
            <span class="slider"></span>
        </label>
        <span style="user-select: none;">Light</span>
    </div>
    <!-- END THEME TOGGLE SWITCH -->

    <!-- Mobile Menu Toggle -->
    <button class="menu-toggle"
            aria-label="Open Menu"
            aria-expanded="false"
            style="display:none; background:none; border:none; font-size:1.5rem; cursor:pointer; color:var(--brand);">
      ☰
    </button>
  </div>
</header>

<!-- Mobile Menu -->
<div class="mobile-menu" id="mobileMenu">
  <div class="mobile-menu-header">
    <div class="brand">
      <?php if (!empty($_SESSION['user']) && $_SESSION['user']['role'] === 'staff'): ?>
        <a href="<?= e(base_url('staff/dashboard.php')) ?>" style="display:flex; align-items:center; gap:8px; text-decoration:none;">
      <?php elseif (!empty($_SESSION['user']) && $_SESSION['user']['role'] === 'customer'): ?>
        <a href="<?= e(base_url('customer/dashboard.php')) ?>" style="display:flex; align-items:center; gap:8px; text-decoration:none;">
      <?php else: ?>
        <a href="<?= e(base_url('index.php')) ?>" style="display:flex; align-items:center; gap:8px; text-decoration:none;">
      <?php endif; ?>
        <svg class="logo" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"
             fill="none" stroke="var(--brand)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
             style="width:28px; height:28px;">
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
        <span class="brand-name" style="
          font-weight:700; font-size:1.35rem;
          background:linear-gradient(90deg,#ec4899,#8b5cf6,#3b82f6,#22c55e,#eab308);
          -webkit-background-clip:text;
          -webkit-text-fill-color:transparent;
          background-clip:text;
          color:transparent;
        ">NexusFix</span>
      </a>
    </div>
    <button class="close-menu" id="closeMenu">&times;</button>
  </div>

  <div class="mobile-nav">
    <?php if (!empty($_SESSION['user']) && $_SESSION['user']['role'] === 'customer'): ?>
      <a href="<?= e(base_url('customer/dashboard.php')) ?>">Dashboard</a>
      <a href="<?= e(base_url('customer/request_new.php')) ?>">New Request</a>
      <a href="<?= e(base_url('customer/requests.php')) ?>">My Requests</a>
      <a href="<?= e(base_url('support/contact.php')) ?>">Contact Support</a>
      <a href="<?= e(base_url('customer/notifications.php')) ?>">Notifications</a>
      <a href="<?= e(base_url('customer/profile.php')) ?>">Edit Profile</a>
      <a href="<?= e(base_url('customer/change_password.php')) ?>">Change Password</a>
    <?php elseif (!empty($_SESSION['user']) && ($_SESSION['user']['role'] === 'staff' || $_SESSION['user']['role'] === 'admin')): ?>
      <a href="<?= e(base_url('staff/dashboard.php')) ?>">Dashboard</a>
      <a href="<?= e(base_url('staff/request_new.php')) ?>">New Ticket</a>
      <a href="<?= e(base_url('staff/registration.php')) ?>">Registration</a>
      <a href="<?= e(base_url('staff/repair.php')) ?>">Repair</a>
      <a href="<?= e(base_url('staff/billing.php')) ?>">Billing</a>
      <a href="<?= e(base_url('staff/shipping.php')) ?>">Shipping</a>
      <!-- Dynamically link to staff profile and change password in mobile menu -->
      <a href="<?= e(base_url('staff/profile.php')) ?>">Edit Profile</a>
      <a href="<?= e(base_url('staff/change_password.php')) ?>">Change Password</a>
    <?php else: ?>
      <a href="<?= e(base_url('index.php#services')) ?>">Services</a>
      <a href="<?= e(base_url('index.php#how')) ?>">How it works</a>
      <a href="<?= e(base_url('index.php#trust')) ?>">Why us</a>
      <a href="<?= e(base_url('index.php#contact')) ?>">Contact</a>
    <?php endif; ?>
  </div>

  <div class="mobile-auth">
    <?php if (!empty($_SESSION['user'])): ?>
      <a href="<?= e(base_url('logout.php')) ?>" class="btn-login">Logout</a>
    <?php else: ?>
      <a href="<?= e(base_url('login.php')) ?>" class="btn-login">Login</a>
      <a href="<?= e(base_url('register.php')) ?>" class="btn-register">Register</a>
    <?php endif; ?>
  </div>
</div>

<!-- Improved Theme Sync and Control Script -->
<script>
  // --- Theme Handling (Sync on load & Listen for changes) ---
  (function() {
    // Use a unique variable name to avoid potential conflicts
    const htmlElementHeader = document.documentElement;
    // Use a unique name for the toggle variable
    const themeToggleHeader = document.getElementById('theme-toggle');

    // Function to apply the theme based on the value
    function applyTheme(themeValue) {
        htmlElementHeader.setAttribute('data-theme', themeValue);
        // Update checkbox state if toggle exists
        if (themeToggleHeader) {
            themeToggleHeader.checked = (themeValue === 'light');
        }
    }

    // --- 1. Sync theme on page load ---
    // Load saved preference from localStorage
    const savedThemeHeader = localStorage.getItem('theme');
    // Apply the saved theme or default to 'dark'
    if (savedThemeHeader) {
        applyTheme(savedThemeHeader);
    } else {
        // Ensure the initial state matches the HTML attribute or defaults to 'dark'
        applyTheme('dark');
    }

    // --- 2. Listen for toggle changes ---
    // Add event listener for theme toggle changes if the element exists
    if (themeToggleHeader) {
        themeToggleHeader.addEventListener('change', function() {
            // Determine the new theme based on the checkbox state
            const newTheme = themeToggleHeader.checked ? 'light' : 'dark';
            applyTheme(newTheme);
            // Save the chosen theme to localStorage
            localStorage.setItem('theme', newTheme);
        });
    }

    // Mobile menu functionality
    const menuToggle = document.querySelector('.menu-toggle');
    const closeMenu = document.getElementById('closeMenu');
    const mobileMenu = document.getElementById('mobileMenu');

    if (menuToggle) {
      menuToggle.addEventListener('click', function() {
        mobileMenu.classList.add('active');
      });
    }

    if (closeMenu) {
      closeMenu.addEventListener('click', function() {
        mobileMenu.classList.remove('active');
      });
    }

    // Click-based dropdown functionality
    const userDropdown = document.getElementById('userDropdown');
    if (userDropdown) {
      const userAvatarTrigger = userDropdown.querySelector('.user-menu-trigger');

      userAvatarTrigger.addEventListener('click', function(e) {
        e.stopPropagation();
        userDropdown.classList.toggle('show');
      });

      // Close dropdown when clicking outside
      document.addEventListener('click', function(e) {
        if (!userDropdown.contains(e.target)) {
          userDropdown.classList.remove('show');
        }
      });
    }
  })();
</script>
<!-- End Theme Script -->
