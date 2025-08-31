<?php
/**
 * Customer Notifications — NexusFix (Ultimate Edition)
 * Inspired by top-tier dashboards for clarity and user experience.
 */

declare(strict_types=1);
require_once __DIR__.'/../../includes/bootstrap.php';
require_role('customer');

$user_id = $_SESSION['user']['id'];
$errors = [];
$note = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['act']) && $_POST['act'] === 'mark_read') {
    if (!csrf_verify($_POST['csrf'] ?? '')) {
        $errors['csrf'] = 'Invalid session token.';
    } else {
        $notification_id = (int)($_POST['nid'] ?? 0);
        if ($notification_id > 0) {
            // Verify notification belongs to user
            $stmt_check = db()->prepare("SELECT id FROM notifications WHERE id = ? AND user_id = ?");
            $stmt_check->execute([$notification_id, $user_id]);
            if ($stmt_check->fetch()) {
                $stmt_mark = db()->prepare("UPDATE notifications SET is_read = 1, read_at = NOW() WHERE id = ?");
                $stmt_mark->execute([$notification_id]);
                $note = 'Notification marked as read.';
                // Update session count
                $stmt_unread_count = db()->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
                $stmt_unread_count->execute([$user_id]);
                $_SESSION['unread_notifications_count'] = $stmt_unread_count->fetchColumn();
            }
        }
    }
}

$stmt_notifications = db()->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC");
$stmt_notifications->execute([$user_id]);
$notifications = $stmt_notifications->fetchAll();

// Ensure session count is current
$stmt_unread_count = db()->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
$stmt_unread_count->execute([$user_id]);
$_SESSION['unread_notifications_count'] = $stmt_unread_count->fetchColumn();
?>
<!doctype html>
<html lang="en" data-theme="dark">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="description" content="View your NexusFix account notifications and important updates.">
  <title>My Notifications — NexusFix</title>
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
      max-width: 960px;
      margin: 2rem auto;
      padding: 1rem;
    }

    /* Header */
    .page-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      flex-wrap: wrap;
      gap: 1rem;
      margin-bottom: 1.5rem;
    }

    .page-header h2 {
      font-size: 1.75rem;
      font-weight: 600;
      margin: 0;
    }

    .page-header .subtitle {
      color: var(--muted);
      font-size: 0.95rem;
      margin-top: 0.25rem;
    }

    /* Empty State */
    .empty-state {
      text-align: center;
      padding: 3rem 1rem;
      color: var(--muted);
      border: 1px dashed var(--border);
      border-radius: var(--radius);
    }

    .empty-state svg {
      width: 60px;
      height: 60px;
      margin-bottom: 1rem;
      opacity: 0.3;
    }

    /* Notification Item */
    .notification-item {
      background: linear-gradient(180deg, var(--card), var(--card-2));
      border: 1px solid var(--border);
      border-radius: var(--radius);
      padding: 1.25rem;
      margin-bottom: 1rem;
      box-shadow: var(--shadow-sm);
      transition: var(--transition);
      position: relative;
    }

    .notification-item:hover {
      transform: translateY(-2px);
      box-shadow: var(--shadow);
    }

    .notification-item.unread {
      border-left: 4px solid var(--accent);
    }

    .notification-item h3 {
      margin: 0 0 0.5rem;
      font-size: 1.1rem;
      font-weight: 600;
    }

    .notification-meta {
      display: flex;
      flex-wrap: wrap;
      gap: 1rem;
      font-size: 0.85rem;
      color: var(--muted);
      margin-bottom: 0.75rem;
    }

    .notification-meta .status {
      font-weight: 500;
    }

    .notification-meta .status.unread {
      color: var(--accent);
    }

    .notification-body {
      color: var(--text);
      line-height: 1.6;
      margin-bottom: 1rem;
    }

    /* Messages */
    .alert {
      padding: 1rem;
      border-radius: 12px;
      margin-bottom: 1.5rem;
      font-size: 0.95rem;
    }

    .alert.success {
      background: rgba(52, 211, 153, 0.15);
      border: 1px solid rgba(52, 211, 153, 0.3);
      color: var(--success);
    }

    .alert.error {
      background: rgba(248, 113, 113, 0.15);
      border: 1px solid rgba(248, 113, 113, 0.3);
      color: var(--danger);
    }

    .alert ul {
      margin: 0.5rem 0 0 1.2rem;
      padding: 0;
    }

    /* Actions */
    .notification-actions {
      display: flex;
      justify-content: flex-end;
      gap: 0.5rem;
    }

    .btn {
      display: inline-flex;
      align-items: center;
      gap: 0.5rem;
      padding: 0.5rem 1rem;
      border-radius: 12px;
      text-decoration: none;
      font-weight: 500;
      font-size: 0.9rem;
      transition: var(--transition);
      border: 1px solid transparent;
      cursor: pointer;
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

  <main aria-labelledby="page-title">
    <!-- REMOVED: Duplicate theme toggle HTML block -->
    <div class="page-header">
      <div>
        <h2 id="page-title">My Notifications</h2>
        <p class="subtitle">
          You have <strong style="color:var(--accent);"><?= e($_SESSION['unread_notifications_count'] ?? 0) ?></strong> unread notification<?= ($_SESSION['unread_notifications_count'] ?? 0) !== 1 ? 's' : '' ?>.
        </p>
      </div>
      <!-- The theme toggle is now handled by header.php, no need to duplicate it here -->
    </div>
    <!-- END REMOVAL -->

    <?php if ($note): ?>
      <div class="alert success" role="alert">
        <strong>Success:</strong> <?= e($note) ?>
      </div>
    <?php endif; ?>

    <?php if ($errors): ?>
      <div class="alert error" role="alert">
        <strong>Error:</strong>
        <?php foreach ($errors as $msg): ?>
          <div><?= e($msg) ?></div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php if (empty($notifications)): ?>
      <div class="empty-state">
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
        </svg>
        <p>No notifications yet.</p>
        <p class="subtitle">Important updates and messages will appear here.</p>
      </div>
    <?php else: ?>
      <div id="notifications-list">
        <?php foreach ($notifications as $n): ?>
          <article class="notification-item <?= $n['is_read'] ? '' : 'unread' ?>" data-id="<?= (int)$n['id'] ?>">
            <h3><?= e($n['title']) ?></h3>
            <div class="notification-meta">
              <span><?= (new DateTime($n['created_at']))->format('M j, Y g:i A') ?></span>
              <span class="status <?= $n['is_read'] ? '' : 'unread' ?>">
                <?= $n['is_read'] ? 'Read' : 'Unread' ?>
                <?php if (!$n['is_read']): ?>
                  <span style="color:var(--accent);">•</span>
                <?php endif; ?>
              </span>
            </div>
            <div class="notification-body">
              <?= nl2br(e($n['body'])) ?>
            </div>
            <?php if (!$n['is_read']): ?>
              <div class="notification-actions">
                <form method="post" style="display:inline;">
                  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                  <input type="hidden" name="nid" value="<?= (int)$n['id'] ?>">
                  <button type="submit" name="act" value="mark_read" class="btn subtle">Mark as Read</button>
                </form>
              </div>
            <?php endif; ?>
          </article>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </main>

  <!-- REMOVED: Duplicate theme toggle script block -->
  <!-- The theme logic is now handled by header.php -->
  <!-- END REMOVAL -->

</body>
</html>