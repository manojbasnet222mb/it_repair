<?php
/**
 * Customer Dashboard — NexusFix (Ultimate Edition)
 * Features:
 * - Clickable status filters
 * - Recent activity feed
 * - Dark/light mode toggle
 * - Time-based welcome
 */

declare(strict_types=1);
require_once __DIR__.'/../../includes/bootstrap.php';
require_role('customer');

$u = $_SESSION['user'];
$unread_notifications_count = (int)($_SESSION['unread_notifications_count'] ?? 0);

// --- Fetch status counts ---
$pdo = db();
$statusCounts = [
  'Received'   => 0,
  'In Repair'  => 0,
  'Billed'     => 0,
  'Shipped'    => 0,
  'Delivered'  => 0
];

try {
  $stmt = $pdo->prepare("
    SELECT status, COUNT(*) as count 
    FROM repair_requests 
    WHERE customer_id = ? 
    GROUP BY status
  ");
  $stmt->execute([$_SESSION['user']['id']]);
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

  foreach ($rows as $row) {
    $status = $row['status'];
    switch ($status) {
      case 'Received':   $statusCounts['Received']   = (int)$row['count']; break;
      case 'In Repair':  $statusCounts['In Repair']  = (int)$row['count']; break;
      case 'Billed':     $statusCounts['Billed']     = (int)$row['count']; break;
      case 'Shipped':    $statusCounts['Shipped']    = (int)$row['count']; break;
      case 'Delivered':  $statusCounts['Delivered']  = (int)$row['count']; break;
    }
  }
} catch (Exception $e) {
  error_log("Dashboard status count error: " . $e->getMessage());
}

// --- Fetch recent activity ---
$recentActivity = [];
try {
  $stmt = $pdo->prepare("
    SELECT r.ticket_code, r.device_type, r.model, h.status, h.changed_at, h.note
    FROM request_status_history h
    JOIN repair_requests r ON r.id = h.request_id
    WHERE r.customer_id = ?
    ORDER BY h.changed_at DESC
    LIMIT 5
  ");
  $stmt->execute([$_SESSION['user']['id']]);
  $recentActivity = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
  error_log("Recent activity fetch error: " . $e->getMessage());
}

// --- Time-based greeting ---
$hour = (new DateTime())->format('H');
$greeting = match(true) {
  $hour < 12 => 'Good morning',
  $hour < 17 => 'Good afternoon',
  default => 'Good evening'
};
?>
<!doctype html>
<html lang="en" data-theme="dark">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="description" content="Your repair dashboard: Start new requests, track status, and manage your account.">
  <title>My Dashboard — NexusFix</title>
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
    }

    /* Header */
    .page-heading {
      display: flex;
      align-items: center;
      justify-content: space-between;
      flex-wrap: wrap;
      gap: 1rem;
      margin-bottom: 16px;
    }

    .page-heading h2 {
      font-size: clamp(1.25rem, 1rem + 1.5vw, 2rem);
      font-weight: 600;
      margin: 0;
    }

    .welcome-sub {
      color: var(--muted);
      font-size: 0.95rem;
      margin-top: 0.25rem;
    }

    /* Search */
    .search {
      display: flex;
      align-items: center;
      background: var(--card);
      border: 1px solid var(--field-border);
      border-radius: 12px;
      padding: 4px;
      max-width: 500px;
      box-shadow: 0 2px 8px rgba(0,0,0,.1);
      transition: var(--transition);
    }

    .search:focus-within {
      border-color: var(--primary);
      box-shadow: 0 0 0 3px rgba(96, 165, 250, 0.2);
    }

    .search input {
      flex: 1;
      background: transparent;
      border: none;
      color: var(--text);
      padding: 0.65rem 0.8rem;
      font-size: 0.95rem;
      outline: none;
      border-radius: 8px;
    }

    .search input::placeholder {
      color: var(--muted);
      opacity: 0.8;
    }

    .search button {
      background: var(--primary);
      color: white;
      border: none;
      border-radius: 8px;
      padding: 0.65rem 1rem;
      font-weight: 600;
      font-size: 0.95rem;
      cursor: pointer;
      transition: var(--transition);
    }

    .search button:hover {
      background: var(--primary-hover);
    }

    .search button:focus-visible {
      outline: 3px solid var(--ring);
      outline-offset: 2px;
    }

    /* Grid */
    .actions {
      display: grid;
      gap: clamp(16px, 2.2vw, 24px);
      margin-top: 24px;
    }

    @media (min-width: 720px) {
      .actions {
        grid-template-columns: repeat(2, 1fr);
      }
    }

    @media (min-width: 1024px) {
      .actions {
        grid-template-columns: repeat(3, 1fr);
      }
    }

    /* Card */
    .card {
      background: linear-gradient(180deg, var(--card), var(--card-2));
      border: 1px solid var(--border);
      border-radius: var(--radius);
      padding: clamp(16px, 2.4vw, 24px);
      box-shadow: var(--shadow-sm);
      transition: var(--transition);
      position: relative;
    }

    .card:hover {
      transform: translateY(-2px);
      box-shadow: var(--shadow);
    }

    .card h3 {
      margin: 0 0 8px;
      font-size: 1.1rem;
      font-weight: 600;
    }

    .card p {
      margin: 0 0 14px;
      color: var(--muted);
      font-size: 0.95rem;
    }

    /* Buttons */
    .btn {
      display: inline-flex;
      align-items: center;
      gap: 0.5rem;
      padding: 0.65rem 1rem;
      border-radius: 12px;
      text-decoration: none;
      font-weight: 600;
      font-size: 0.95rem;
      transition: var(--transition);
      border: 1px solid transparent;
    }

    .btn.primary {
      background: var(--primary);
      color: white;
    }

    .btn.primary:hover {
      background: var(--primary-hover);
      transform: translateY(-1px);
    }

    .btn.outline {
      background: transparent;
      border-color: var(--primary);
      color: var(--primary);
    }

    .btn.outline:hover {
      background: rgba(96, 165, 250, 0.08);
    }

    .btn.subtle {
      background: rgba(255,255,255,.06);
      color: var(--text);
      border-color: rgba(255,255,255,.08);
    }

    .btn.subtle:hover {
      background: rgba(255,255,255,.1);
    }

    .btn:focus-visible {
      outline: 3px solid var(--ring);
      outline-offset: 3px;
    }

    /* Badge */
    .badge {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-width: 20px;
      height: 20px;
      padding: 0 6px;
      border-radius: 999px;
      font-size: 0.75rem;
      font-weight: 700;
      background: var(--danger);
      color: white;
      animation: pulse 2s infinite 1s;
    }

    @keyframes pulse {
      0% { box-shadow: 0 0 0 0 rgba(248, 113, 113, 0.4); }
      70% { box-shadow: 0 0 0 6px rgba(248, 113, 113, 0); }
      100% { box-shadow: 0 0 0 0 rgba(248, 113, 113, 0); }
    }

    /* Status Legend */
    .legend {
      display: flex;
      flex-wrap: wrap;
      gap: 0.6rem 0.8rem;
      margin-top: 10px;
    }

    .chip {
      --c: var(--muted);
      display: inline-flex;
      align-items: center;
      gap: 0.5rem;
      padding: 0.4rem 0.7rem;
      border-radius: 999px;
      background: rgba(255,255,255,0.06);
      color: var(--c);
      font-size: 0.85rem;
      border: 1px solid rgba(255,255,255,0.08);
      cursor: pointer;
      transition: var(--transition);
    }

    .chip:hover {
      background: rgba(255,255,255,0.12);
      transform: scale(1.03);
    }

    .chip .dot {
      width: 0.65rem;
      height: 0.65rem;
      border-radius: 50%;
      background: var(--c);
    }

    .chip.received { --c: #a3a3a3; }
    .chip.received:hover { --c: #b8b8b8; }
    .chip.repair { --c: var(--primary); }
    .chip.billed { --c: var(--warning); }
    .chip.shipped { --c: #a78bfa; }
    .chip.delivered { --c: var(--success); }

    /* Activity Feed */
    .activity {
      list-style: none;
      padding: 0;
      margin: 0;
      display: flex;
      flex-direction: column;
      gap: 10px;
    }

    .activity-item {
      display: flex;
      gap: 0.75rem;
      padding: 0.5rem 0;
      border-bottom: 1px solid var(--field-border);
      color: var(--muted);
      font-size: 0.9rem;
    }

    .activity-item:last-child {
      border-bottom: none;
    }

    .activity-item strong {
      color: var(--text);
    }

    .activity-date {
      font-size: 0.85rem;
      color: var(--muted);
    }

    /* Tips */
    .tips {
      list-style: none;
      padding: 0;
      margin: 0;
      display: grid;
      gap: 0.75rem;
    }

    .tips li {
      display: flex;
      gap: 0.75rem;
      align-items: flex-start;
      color: var(--muted);
      font-size: 0.9rem;
    }

    .tips svg {
      flex-shrink: 0;
      color: var(--primary);
    }

    /* Screen reader */
    .sr-only {
      position: absolute;
      width: 1px;
      height: 1px;
      padding: 0;
      margin: -1px;
      overflow: hidden;
      clip: rect(0, 0, 0, 0);
      white-space: nowrap;
      border: 0;
    }

    /* Reduced motion */
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
  <?php require __DIR__.'/../../includes/header.php'; ?>

  <main aria-labelledby="pg-title">
    <header class="page-heading">
      <div>
        <h2 id="pg-title"><?= e($greeting) ?>, <?= e($u['name']) ?></h2>
        <p class="welcome-sub">Manage your devices, track repairs, and get support when needed.</p>
      </div>

      <div style="display:flex;gap:1rem;align-items:center;">
        <form class="search" role="search" action="<?= e(base_url('customer/requests.php')) ?>" method="get">
          <label for="q" class="sr-only">Search your repair requests</label>
          <input
            id="q"
            name="q"
            type="search"
            placeholder="Search by ID, device, or keyword…"
            autocomplete="off"
            aria-label="Search requests"
          >
          <button type="submit" aria-label="Search">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <circle cx="11" cy="11" r="8"/>
              <line x1="21" y1="21" x2="16.65" y2="16.65"/>
            </svg>
          </button>
        </form>
        <!-- The theme toggle is now handled by header.php, no need to duplicate it here -->
      </div>
    </header>

    <section class="actions" aria-label="Dashboard quick actions">
      <!-- New Repair -->
      <article class="card" aria-labelledby="new-repair-title">
        <h3 id="new-repair-title">Start a New Repair</h3>
        <p>Get your device fixed fast with a detailed service request.</p>
        <a class="btn primary" href="<?= e(base_url('customer/request_new.php')) ?>">
          <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <line x1="12" y1="5" x2="12" y2="19"/>
            <line x1="5" y1="12" x2="19" y2="12"/>
          </svg>
          New Request
        </a>
      </article>

      <!-- My Requests -->
      <article class="card" aria-labelledby="my-requests-title">
        <h3 id="my-requests-title">My Repair Requests</h3>
        <p>Track the status of all your active and past repairs.</p>
        <div class="legend" aria-label="Repair status counts">
          <?php foreach (['Received', 'In Repair', 'Billed', 'Shipped', 'Delivered'] as $status): ?>
            <a href="<?= e(base_url("customer/requests.php?status=" . urlencode($status))) ?>" class="chip <?= strtolower(str_replace(' ', '-', $status)) ?>" style="text-decoration:none;color:inherit;">
              <span class="dot"></span>
              <?= e($status) ?>
              <strong>(<?= e((string)$statusCounts[$status]) ?>)</strong>
            </a>
          <?php endforeach; ?>
        </div>
        <a class="btn outline" href="<?= e(base_url('customer/requests.php')) ?>">
          <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>
          </svg>
          View All Requests
        </a>
      </article>

      <!-- Recent Activity -->
      <article class="card" aria-labelledby="activity-title">
        <h3 id="activity-title">Recent Activity</h3>
        <?php if (empty($recentActivity)): ?>
          <p>No recent activity.</p>
        <?php else: ?>
          <ul class="activity">
            <?php foreach ($recentActivity as $act): ?>
              <li class="activity-item">
                <div>
                  <div>
                    <strong><?= e($act['ticket_code']) ?></strong>:
                    <?= e($act['device_type']) ?>
                    <?= e($act['model'] ?: '') ?>
                  </div>
                  <div>Updated to <strong><?= e($act['status']) ?></strong></div>
                  <div class="activity-date">
                    <?= (new DateTime($act['changed_at']))->format('M j, g:i A') ?>
                  </div>
                </div>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
        <a class="btn subtle" href="<?= e(base_url('customer/requests.php')) ?>" style="margin-top:10px;">View All Updates</a>
      </article>

      <!-- Profile -->
      <article class="card" aria-labelledby="profile-title">
        <h3 id="profile-title">My Profile</h3>
        <p>Update your contact, address, and preferences.</p>
        <a class="btn subtle" href="<?= e(base_url('customer/profile.php')) ?>">
          <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M20 21a8 8 0 0 0-16 0"/>
            <circle cx="12" cy="7" r="4"/>
          </svg>
          Edit Profile
        </a>
      </article>

      <!-- Notifications -->
      <article class="card" aria-labelledby="notifications-title">
        <h3 id="notifications-title">Notifications</h3>
        <p>You have
          <strong style="color:var(--accent);"><?= e($unread_notifications_count) ?></strong>
          unread <?= $unread_notifications_count === 1 ? 'message' : 'messages' ?>.
        </p>
        <a class="btn subtle" href="<?= e(base_url('customer/notifications.php')) ?>">
          <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/>
            <path d="M13.73 21a2 2 0 0 1-3.46 0"/>
          </svg>
          View Notifications
          <?php if ($unread_notifications_count > 0): ?>
            <span class="badge" aria-label="<?= e((string)$unread_notifications_count) ?> unread notifications">
              <?= e((string)$unread_notifications_count) ?>
            </span>
          <?php endif; ?>
        </a>
      </article>

      <!-- Help & Support -->
      <article class="card" aria-labelledby="help-title">
        <h3 id="help-title">Need Help?</h3>
        <p>Get answers fast or contact our support team.</p>
        <p style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
          <a class="btn outline" href="<?= e(base_url('support/kb.php')) ?>">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/>
              <path d="M4 4v15.5A2.5 2.5 0 0 0 6.5 22H20V6a2 2 0 0 0-2-2H6"/>
            </svg>
            FAQs
          </a>
          <a class="btn primary" href="<?= e(base_url('support/contact.php')) ?>">
            Contact Support
          </a>
        </p>
      </article>

      <!-- Tips -->
      <article class="card" aria-labelledby="tips-title">
        <h3 id="tips-title">Pro Tips for Faster Service</h3>
        <ul class="tips">
          <li>
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10"/>
            </svg>
            <span>Include passcodes only if needed for diagnostics.</span>
          </li>
          <li>
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <path d="M21 16V8a2 2 0 0 0-1-1.73L13 2.27a2 2 0 0 0-2 0L4 6.27A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/>
              <path d="M3.27 6.96L12 12.01l8.73-5.05"/>
              <path d="M12 22.08V12"/>
            </svg>
            <span>Pack securely. Remove SIM/SD cards before shipping.</span>
          </li>
          <li>
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
              <path d="M14 2v6h6"/>
              <path d="M16 13H8"/>
              <path d="M16 17H8"/>
              <path d="M10 9H8"/>
            </svg>
            <span>Describe symptoms clearly — include when and how they occur.</span>
          </li>
        </ul>
      </article>
    </section>
  </main>

  <script>
    // --- Auto-focus search ---
    document.addEventListener('DOMContentLoaded', () => {
      const search = document.getElementById('q');
      if (window.innerWidth > 600 && search) {
        search.focus();
      }
    });
  </script>
</body>
</html>