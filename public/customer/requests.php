<?php
/**
 * Customer Requests — NexusFix (Ultimate Edition)
 * World-class UX inspired by Apple, Microsoft, and Best Buy.
 * Enhanced: Attachments are now clickable to view or download.
 */

declare(strict_types=1);
require_once __DIR__.'/../../includes/bootstrap.php'; // Ensure bootstrap is used
require_role('customer');

$user_id = $_SESSION['user']['id'];

// Search & filter
$search = trim($_GET['q'] ?? '');
$status_filter = $_GET['status'] ?? '';
$valid_statuses = ['Received', 'In Repair', 'Billed', 'Shipped', 'Delivered', 'Rejected', 'Cancelled'];

// Build query
$sql = "SELECT * FROM repair_requests WHERE customer_id = ?";
$params = [$user_id];

if ($search) {
  $sql .= " AND (ticket_code LIKE ? OR device_type LIKE ? OR brand LIKE ? OR model LIKE ? OR issue_description LIKE ?)";
  $like = '%' . $search . '%';
  $params = array_merge($params, [$like, $like, $like, $like, $like]);
}

if ($status_filter && in_array($status_filter, $valid_statuses, true)) {
  $sql .= " AND status = ?";
  $params[] = $status_filter;
}

$sql .= " ORDER BY created_at DESC";

$stmt = db()->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$highlight = $_GET['new'] ?? null;

// Status badge generator
function status_badge(string $s): string {
  $map = [
    'Received'   => 'bg-blue-100 text-blue-800 border-blue-200',
    'In Repair'  => 'bg-cyan-100 text-cyan-800 border-cyan-200',
    'Billed'     => 'bg-yellow-100 text-yellow-800 border-yellow-200',
    'Shipped'    => 'bg-indigo-100 text-indigo-800 border-indigo-200',
    'Delivered'  => 'bg-green-100 text-green-800 border-green-200',
    'Rejected'   => 'bg-red-100 text-red-800 border-red-200',
    'Cancelled'  => 'bg-gray-100 text-gray-800 border-gray-200'
  ];
  $cls = $map[$s] ?? 'bg-gray-100 text-gray-800 border-gray-200';
  return '<span class="status-badge ' . e($cls) . '" style="padding:4px 8px; border-radius:6px; font-size:0.85rem; font-weight:500; border:1px solid">' . e($s) . '</span>';
}

// --- Helper function to get attachments for a request ---
function get_request_attachments(int $request_id): array {
    $stmt = db()->prepare("SELECT file_path, file_type FROM request_attachments WHERE request_id = ? ORDER BY uploaded_at ASC");
    $stmt->execute([$request_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// --- Helper function to determine if a file type is an image ---
function is_image_type(string $file_type): bool {
    $image_types = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    return in_array(strtolower($file_type), $image_types);
}

// --- Helper function to determine if a file type is a video ---
function is_video_type(string $file_type): bool {
    $video_types = ['mp4', 'mov', 'avi', 'mkv', 'webm'];
    return in_array(strtolower($file_type), $video_types);
}

// --- Helper function to get the full URL for a file path ---
function get_file_url(string $file_path): string {
    $base = rtrim(dirname(base_url()), '/'); // removes /public
    return $base . '/' . ltrim($file_path, '/');
}
?>

<!doctype html>
<html lang="en" data-theme="dark">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="description" content="View and manage your repair requests. Track status, view history, and pay invoices.">
  <title>My Repair Requests — NexusFix</title>
  <link rel="stylesheet" href="../../assets/css/styles.css">
  <style>
    :root {
      --bg: #0b0c0f;
      --card: #101218;
      --text: #e8eaf0;
      --muted: #a6adbb;
      --border: #1f2430;
      --field-border: #2a3242;
      --primary: #60a5fa;
      --shadow: 0 10px 30px rgba(0,0,0,.35);
      --shadow-sm: 0 4px 12px rgba(0,0,0,.18);
      --radius: 16px;
      --transition: all 0.2s ease;
    }

    :root[data-theme="light"] {
      --bg: #f7f8fb;
      --card: #ffffff;
      --text: #0b0c0f;
      --muted: #5b6172;
      --border: #e5e7eb;
      --field-border: #cbd5e1;
      --primary: #3b82f6;
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
    }

    main {
      max-width: 1200px;
      margin: 2rem auto;
      padding: 1rem;
    }

    h2 {
      font-size: 1.75rem;
      font-weight: 600;
      margin-bottom: 1rem;
    }

    .subtitle {
      color: var(--muted);
      font-size: 0.95rem;
      margin-top: 0.25rem;
    }

    /* Filters */
    .filters {
      display: flex;
      flex-wrap: wrap;
      gap: 12px;
      margin-bottom: 1.5rem;
      align-items: center;
    }

    .filters input, .filters select {
      padding: 0.65rem 0.9rem;
      border: 1px solid var(--field-border);
      border-radius: 12px;
      background: var(--card);
      color: var(--text);
      font-size: 0.95rem;
    }

    .filters input:focus, .filters select:focus {
      outline: none;
      border-color: var(--primary);
    }

    /* Request Card */
    .request-item {
      background: var(--card);
      border: 1px solid var(--border);
      border-radius: var(--radius);
      padding: 1rem;
      margin-bottom: 1rem;
      box-shadow: var(--shadow-sm);
      transition: var(--transition);
    }

    .request-item:hover {
      transform: translateY(-2px);
      box-shadow: var(--shadow);
    }

    .request-item h3 {
      margin: 0 0 8px;
      font-size: 1.15rem;
      font-weight: 600;
    }

    .request-meta {
      display: flex;
      flex-wrap: wrap;
      gap: 1rem;
      margin-bottom: 12px;
      font-size: 0.95rem;
      color: var(--muted);
    }

    .request-meta strong {
      color: var(--text);
    }

    .status-row {
      display: flex;
      align-items: center;
      gap: 0.5rem;
      margin-bottom: 0.5rem;
    }

    /* Timeline */
    .timeline {
      margin: 12px 0;
      padding-left: 1rem;
      border-left: 2px solid var(--field-border);
      list-style: none;
    }

    .timeline-item {
      position: relative;
      padding: 0.5rem 0;
    }

    .timeline-item::before {
      content: '';
      position: absolute;
      left: -13px;
      top: 10px;
      width: 8px;
      height: 8px;
      border-radius: 50%;
      background: var(--primary);
      border: 2px solid var(--card);
    }

    .timeline-date {
      font-size: 0.8rem;
      color: var(--muted);
    }

    /* Invoice */
    .invoice-actions {
      margin-top: 12px;
      padding-top: 12px;
      border-top: 1px solid var(--field-border);
    }

    .btn {
      padding: 0.6rem 1rem;
      border-radius: 12px;
      border: 1px solid transparent;
      background: var(--primary);
      color: white;
      font-weight: 600;
      cursor: pointer;
      transition: var(--transition);
    }

    .btn:hover {
      background: #4f9cf9;
      transform: translateY(-1px);
    }

    .trust {
      color: var(--muted);
      font-size: 0.9rem;
      margin: 10px 0 0;
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

    /* Details */
    details > summary {
      cursor: pointer;
      user-select: none;
      font-weight: 500;
      color: var(--primary);
      padding: 0.5rem 0;
    }

    details > summary::marker {
      content: '▶ ';
      font-size: 0.8rem;
    }

    details[open] > summary::marker {
      content: '▼ ';
    }

    /* Attachments */
    .attachments-grid {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
    }
    .attachment-item {
        position: relative;
        width: 100px;
        height: 100px;
        border-radius: 8px;
        overflow: hidden;
        border: 1px solid var(--field-border);
        background-color: var(--card);
        cursor: pointer;
    }
    .attachment-item img,
    .attachment-item video {
        width: 100%;
        height: 100%;
        object-fit: cover;
        transition: transform 0.2s ease;
    }
    .attachment-item:hover img,
    .attachment-item:hover video {
        transform: scale(1.05);
    }
    .attachment-item video {
        background: #111;
    }
    .attachment-placeholder {
        display: flex;
        align-items: center;
        justify-content: center;
        width: 100%;
        height: 100%;
        background: var(--field-border);
        color: var(--muted);
        font-size: 0.75rem;
        font-weight: bold;
    }
    .attachment-filename {
        position: absolute;
        bottom: 0;
        left: 0;
        right: 0;
        background: rgba(0,0,0,0.7);
        color: white;
        font-size: 0.65rem;
        padding: 2px 4px;
        text-align: center;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    /* Lightbox Modal */
    .lightbox {
      display: none;
      position: fixed;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      background: rgba(0,0,0,0.9);
      z-index: 1000;
      justify-content: center;
      align-items: center;
      flex-direction: column;
    }
    .lightbox.active {
      display: flex;
    }
    .lightbox-content {
      max-width: 90vw;
      max-height: 85vh;
      border-radius: 12px;
      overflow: hidden;
      box-shadow: 0 10px 50px rgba(0,0,0,0.5);
    }
    .lightbox-content img,
    .lightbox-content video {
      max-width: 100%;
      max-height: 80vh;
      display: block;
    }
    .lightbox-video {
      background: #000;
    }
    .lightbox-close {
      position: absolute;
      top: 20px;
      right: 20px;
      width: 40px;
      height: 40px;
      border: none;
      background: rgba(255,255,255,0.2);
      color: white;
      font-size: 1.5rem;
      border-radius: 50%;
      cursor: pointer;
      backdrop-filter: blur(5px);
    }
    .lightbox-close:hover {
      background: rgba(255,255,255,0.3);
    }
    .lightbox-filename {
      margin-top: 10px;
      color: #aaa;
      font-size: 0.9rem;
    }

    .no-attachments {
        color: var(--muted);
        font-size: 0.9rem;
        font-style: italic;
    }

    @media (prefers-reduced-motion: reduce) {
      * {
        transition: none !important;
        animation: none !important;
      }
    }
  </style>
</head>
<body>
  <?php require __DIR__.'/../../includes/header.php'; ?>

  <main aria-labelledby="page-title">
    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:1rem;margin-bottom:1rem;">
      <div>
        <h2 id="page-title">My Repair Requests</h2>
        <p class="subtitle">
          <?= e($rows ? count($rows) . ' request' . (count($rows) !== 1 ? 's' : '') . ' found' : 'No requests yet') ?>
        </p>
      </div>
    </div>

    <?php if ($highlight): ?>
      <div class="request-item" style="border-left:4px solid var(--primary);">
        <strong>Success:</strong> Your ticket <strong><?= e($highlight) ?></strong> was created and is <em>Received</em>.
      </div>
    <?php endif; ?>

    <?php if (!$rows): ?>
      <div class="empty-state">
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
        </svg>
        <p>No repair requests yet.</p>
        <a href="<?= e(base_url('customer/request_new.php')) ?>" class="btn">Start a New Repair</a>
      </div>
    <?php else: ?>
      <!-- Filters -->
      <div class="filters" role="search">
        <input type="text" id="request-search" placeholder="Search by ticket, device, brand, model…" autocomplete="off">
        <select id="status-filter">
          <option value="">All Statuses</option>
          <?php foreach ($valid_statuses as $s): ?>
            <option value="<?= e(strtolower($s)) ?>" <?= $status_filter === $s ? 'selected' : '' ?>>
              <?= e($s) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div id="requests-list">
        <?php foreach ($rows as $r): ?>
          <?php
            // Status history
            $h = db()->prepare("SELECT status, note, created_at FROM request_status_history WHERE request_id=? ORDER BY id ASC");
            $h->execute([$r['id']]);
            $history = $h->fetchAll();

            // Latest invoice
            $inv = db()->prepare("SELECT * FROM invoices WHERE request_id=? ORDER BY id DESC LIMIT 1");
            $inv->execute([$r['id']]);
            $invoice = $inv->fetch();

            // Fetch attachments
            $attachments = get_request_attachments((int)$r['id']);
          ?>
          <article class="request-item"
            data-ticket="<?= strtolower($r['ticket_code']) ?>"
            data-brand="<?= strtolower($r['brand'] ?: '') ?>"
            data-model="<?= strtolower($r['model'] ?: '') ?>"
            data-device="<?= strtolower($r['device_type']) ?>"
            data-status="<?= strtolower($r['status']) ?>">

            <h3><?= e($r['ticket_code']) ?> — <?= e($r['device_type']) ?>
              <?= $r['brand'] ? ' · <span style="color:var(--muted)">' . e($r['brand']) . '</span>' : '' ?>
              <?= $r['model'] ? ' · <span style="color:var(--muted)">' . e($r['model']) . '</span>' : '' ?>
            </h3>

            <div class="request-meta">
              <div class="status-row">
                <strong>Status:</strong>
                <?= status_badge($r['status']) ?>
              </div>
              <div><strong>Created:</strong> <?= e((new DateTime($r['created_at']))->format('M j, Y g:i A')) ?></div>
              <div><strong>Priority:</strong> <strong style="color:#fbbf24;"><?= e(ucfirst($r['priority'])) ?></strong></div>
            </div>

            <details>
              <summary>Details & Timeline</summary>
              <div style="display:flex; gap:24px; margin-top:12px; flex-wrap:wrap;">
                <!-- Left: Text Details -->
                <div style="flex:1; min-width:300px;">
                  <p><strong>Issue:</strong> <?= nl2br(e($r['issue_description'])) ?></p>
                  <p><strong>Service:</strong> <?= e(ucfirst($r['service_type'])) ?> •
                     <strong>Contact:</strong> <?= e($r['preferred_contact']) ?></p>
                  <?php if ($r['address']): ?>
                    <p><strong>Address:</strong> <?= e($r['address']) ?>, <?= e($r['city']) ?>, <?= e($r['postal_code']) ?></p>
                  <?php endif; ?>
                  <?php if ($r['accessories']): ?>
                    <p><strong>Accessories:</strong> <?= e($r['accessories']) ?></p>
                  <?php endif; ?>

                  <h4 style="margin:1rem 0 0.75rem; font-size:1rem;">Timeline</h4>
                  <ol class="timeline">
                    <?php foreach ($history as $ev): ?>
                      <li class="timeline-item">
                        <div><strong><?= e($ev['status']) ?></strong></div>
                        <?php if ($ev['note']): ?>
                          <div style="font-size:0.9rem; margin-top:2px;"><?= e($ev['note']) ?></div>
                        <?php endif; ?>
                        <div class="timeline-date">
                          <?= (new DateTime($ev['created_at']))->format('M j, g:i A') ?>
                        </div>
                      </li>
                    <?php endforeach; ?>
                  </ol>
                </div>

                <!-- Right: Attachments -->
                <div style="flex:1; min-width:200px;">
                  <h4 style="margin:0 0 0.75rem; font-size:1rem;">Photos & Files</h4>
                  <?php if (empty($attachments)): ?>
                    <span class="no-attachments">No files attached.</span>
                  <?php else: ?>
                    <div class="attachments-grid">
                      <?php foreach ($attachments as $attachment): ?>
                        <?php
                          $file_path = $attachment['file_path'];
                          $file_type = strtolower($attachment['file_type']);
                          $full_url = get_file_url($file_path);
                          $filename = basename($file_path);
                          $is_image = is_image_type($file_type);
                          $is_video = is_video_type($file_type);
                        ?>
                        <div class="attachment-item"
                             onclick="openLightbox('<?= e(addslashes($full_url)) ?>', '<?= e(addslashes($filename)) ?>', '<?= $is_image ? 'image' : ($is_video ? 'video' : 'file') ?>')"
                             title="Click to view: <?= e($filename) ?>">
                          <?php if ($is_image): ?>
                            <img src="<?= e($full_url) ?>" alt="Image: <?= e($filename) ?>" loading="lazy">
                          <?php elseif ($is_video): ?>
                            <video muted playsinline>
                              <source src="<?= e($full_url) ?>" type="video/<?= e($file_type) ?>">
                              <div class="attachment-placeholder">🎥</div>
                            </video>
                          <?php else: ?>
                            <div class="attachment-placeholder"><?= strtoupper(e($file_type)) ?></div>
                          <?php endif; ?>
                          <div class="attachment-filename"><?= e($filename) ?></div>
                        </div>
                      <?php endforeach; ?>
                    </div>
                  <?php endif; ?>
                </div>
              </div>
            </details>

            <!-- Invoice Actions -->
            <?php if ($invoice && $invoice['payment_status'] === 'Unpaid'): ?>
              <div class="invoice-actions">
                <form action="<?= e(base_url('customer/pay_invoice.php')) ?>" method="post" style="display:inline;">
                  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                  <input type="hidden" name="invoice_id" value="<?= (int)$invoice['id'] ?>">
                  <button class="btn">Pay Invoice #<?= (int)$invoice['id'] ?></button>
                </form>
              </div>
            <?php elseif ($invoice && $invoice['payment_status'] === 'Paid'): ?>
              <div class="invoice-actions">
                <p class="trust">
                  ✅ Invoice #<?= (int)$invoice['id'] ?> paid on
                  <?= (new DateTime($invoice['payment_date']))->format('M j, Y') ?>
                </p>
              </div>
            <?php endif; ?>
          </article>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </main>

  <!-- Lightbox Modal -->
  <div id="lightbox" class="lightbox">
    <button class="lightbox-close" onclick="closeLightbox()">×</button>
    <div id="lightbox-content" class="lightbox-content"></div>
    <div id="lightbox-filename" class="lightbox-filename"></div>
  </div>

  <script>
    const lightbox = document.getElementById('lightbox');
    const lightboxContent = document.getElementById('lightbox-content');
    const lightboxFilename = document.getElementById('lightbox-filename');

    function openLightbox(url, filename, type) {
      lightboxContent.innerHTML = '';
      lightboxFilename.textContent = filename;

      if (type === 'image') {
        const img = document.createElement('img');
        img.src = url;
        img.alt = filename;
        img.style.borderRadius = '12px';
        lightboxContent.appendChild(img);
      } else if (type === 'video') {
        const video = document.createElement('video');
        video.src = url;
        video.controls = true;
        video.autoplay = true;
        video.muted = false;
        video.style.borderRadius = '12px';
        lightboxContent.appendChild(video);
      } else {
        const link = document.createElement('a');
        link.href = url;
        link.target = "_blank";
        link.textContent = `📄 View or download: ${filename}`;
        link.style.color = 'white';
        link.style.textDecoration = 'underline';
        lightboxContent.appendChild(link);
      }

      lightbox.classList.add('active');
      document.body.style.overflow = 'hidden';
    }

    function closeLightbox() {
      lightbox.classList.remove('active');
      document.body.style.overflow = '';
      const video = lightboxContent.querySelector('video');
      if (video) video.pause();
    }

    // Close on click outside
    lightbox.addEventListener('click', (e) => {
      if (e.target === lightbox) closeLightbox();
    });

    // Close on Escape key
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape' && lightbox.classList.contains('active')) {
        closeLightbox();
      }
    });

    // Search & Filter
    const searchInput = document.getElementById('request-search');
    const statusFilter = document.getElementById('status-filter');
    const items = document.querySelectorAll('.request-item');

    function debounce(func, delay) {
      let timer;
      return function () {
        const context = this, args = arguments;
        clearTimeout(timer);
        timer = setTimeout(() => func.apply(context, args), delay);
      };
    }

    const applyFilters = debounce(() => {
      const q = searchInput.value.toLowerCase().trim();
      const status = statusFilter.value.toLowerCase().trim();

      items.forEach(item => {
        const text = [
          item.dataset.ticket,
          item.dataset.brand,
          item.dataset.model,
          item.dataset.device
        ].join(' ').toLowerCase();

        const matchText = !q || text.includes(q);
        const matchStatus = !status || item.dataset.status === status;

        item.style.display = (matchText && matchStatus) ? 'block' : 'none';
      });
    }, 150);

    searchInput.addEventListener('input', applyFilters);
    statusFilter.addEventListener('change', applyFilters);

    // Auto-focus search
    document.addEventListener('DOMContentLoaded', () => {
      if (window.innerWidth > 600) {
        searchInput.focus();
      }
    });
  </script>
</body>
</html>