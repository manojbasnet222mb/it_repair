<?php
declare(strict_types=1);

// IMPORTANT: centralize session/config in bootstrap to avoid ini_set() warnings
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/guard.php';
require_role('staff','admin');

$u = $_SESSION['user'] ?? ['name'=>'Staff','role'=>'staff'];
$pdo = db();

/* ---------------------------
   CONSTANTS / MAPS
----------------------------*/
$kpiBuckets = ['Received','In Repair','Billed','Shipped','Delivered']; // for KPI tiles

function tab_for_status(?string $s): string {
  $s = trim((string)$s);
  if ($s === '' || $s === 'Received' || $s === 'Device Received' || $s === 'At Warehouse' || $s === 'Pickup In Progress' || $s === 'Onsite In Progress' || $s === 'Onsite Repair Started' || $s === 'Onsite Completed') {
    if ($s === '' || $s === 'Received') return 'new';
    if (in_array($s, ['Pickup In Progress','Device Received','At Warehouse','Onsite In Progress','Onsite Repair Started','Onsite Completed'], true)) return 'pickup';
  }
  if ($s === 'In Repair') return 'repair';
  if (in_array($s, ['Rejected','Cancelled','Billed','Delivered'], true)) return 'history';
  return 'new';
}

function normalize_status(?string $s): string {
  $s = trim((string)$s);
  return $s !== '' ? $s : 'Received';
}

/* ---------------------------
   KPI / COUNTS & HEALTH METRICS
----------------------------*/
$counts = array_fill_keys($kpiBuckets, 0);
$today_new = 0;
$unassigned = 0;
$overdue_billing = 0;
$mttr_hours = 0.0;
$backlog = 0;
$statusDist = [];
$dailyNew = [];
$labels = [];

try {
    // status distribution (normalized)
    $stmt_counts = $pdo->query("
      SELECT COALESCE(NULLIF(status,''),'Received') AS s, COUNT(*) c
      FROM repair_requests
      GROUP BY COALESCE(NULLIF(status,''),'Received')
    ");
    while ($r = $stmt_counts->fetch()) {
        $s = $r['s'];
        if (isset($counts[$s])) $counts[$s] = (int)$r['c'];
    }

    // Today’s new requests
    $today_new = (int)$pdo->query("SELECT COUNT(*) FROM repair_requests WHERE DATE(created_at)=CURDATE()")->fetchColumn();

    // Unassigned requests
    $unassigned = (int)$pdo->query("
      SELECT COUNT(*) FROM repair_requests rr
      WHERE NOT EXISTS (
        SELECT 1 FROM request_assignments ra WHERE ra.request_id = rr.id
      )
    ")->fetchColumn();

    // Overdue billing (Billed > 7 days ago)
    $overdue_billing = (int)$pdo->query("
      SELECT COUNT(*) FROM repair_requests
      WHERE COALESCE(NULLIF(status,''),'Received')='Billed'
        AND created_at < (NOW() - INTERVAL 7 DAY)
    ")->fetchColumn();

    /* ---------------------------
       HEALTH METRICS (MTTR, BACKLOG)
    ----------------------------*/
    // MTTR (hours): average time from Created -> first time status became 'In Repair'
    $stmt_mttr = $pdo->query("
      SELECT AVG(TIMESTAMPDIFF(HOUR, rr.created_at, h.changed_at)) AS mttr
      FROM repair_requests rr
      JOIN request_status_history h
        ON h.request_id = rr.id AND h.status='In Repair'
    ");
    $mttr_result = $stmt_mttr->fetchColumn();
    $mttr_hours = $mttr_result ? round((float)$mttr_result, 1) : 0.0;

    // Backlog: open tickets older than 7 days (not Delivered/Cancelled)
    $backlog = (int)$pdo->query("
      SELECT COUNT(*) FROM repair_requests
      WHERE COALESCE(NULLIF(status,''),'Received') NOT IN ('Delivered','Cancelled')
        AND created_at < (NOW() - INTERVAL 7 DAY)
    ")->fetchColumn();

    /* ---------------------------
       CHART DATA
    ----------------------------*/
    foreach ($kpiBuckets as $s) {
        $statusDist[] = ['label'=>e($s), 'value'=>$counts[$s]]; // Escape for JSON
    }

    // Daily new requests (last 14 days)
    $dailyNew = array_fill(0, 14, 0);
    $labels = [];
    for ($i=13; $i>=0; $i--) {
        $labels[] = date('Y-m-d', strtotime("-$i day"));
    }
    $stmt_daily = $pdo->query("
      SELECT DATE(created_at) d, COUNT(*) c
      FROM repair_requests
      WHERE created_at >= (CURDATE() - INTERVAL 13 DAY)
      GROUP BY DATE(created_at)
      ORDER BY d
    ");
    while ($r = $stmt_daily->fetch()) {
      $idx = array_search($r['d'], $labels, true);
      if ($idx !== false) {
          $dailyNew[$idx] = (int)$r['c'];
      }
    }

} catch (PDOException $e) {
    app_log('error', 'Dashboard data fetch error', ['exception' => $e]);
    // Data will remain at default/zero values, UI handles this gracefully
}

/* ---------------------------
   MY ASSIGNMENTS
----------------------------*/
$my = [];
try {
    $stmt_my = $pdo->prepare("
      SELECT ra.*, rr.id AS request_id, rr.ticket_code, rr.device_type, rr.brand, rr.model,
             COALESCE(NULLIF(rr.status,''),'Received') AS status, rr.priority, rr.created_at
      FROM request_assignments ra
      JOIN repair_requests rr ON rr.id = ra.request_id
      WHERE ra.assigned_to = ?
        AND ra.desk IN ('Registration','Repair','Billing','Shipping')
      ORDER BY ra.assigned_at DESC
      LIMIT 40
    ");
    $stmt_my->execute([$u['id']]);
    $my = $stmt_my->fetchAll();
} catch (PDOException $e) {
    app_log('error', 'My Assignments fetch error', ['exception' => $e]);
    // $my remains empty, UI handles this
}

/* ---------------------------
   LATEST REQUESTS (sortable table)
----------------------------*/
$latest = [];
try {
    $stmt_latest = $pdo->query("
      SELECT rr.id, rr.ticket_code, rr.device_type, rr.brand, rr.model,
             COALESCE(NULLIF(rr.status,''),'Received') AS status, rr.created_at
      FROM repair_requests rr
      ORDER BY rr.id DESC
      LIMIT 12
    ");
    $latest = $stmt_latest->fetchAll();
} catch (PDOException $e) {
    app_log('error', 'Latest Requests fetch error', ['exception' => $e]);
    // $latest remains empty, UI handles this
}

/* ---------------------------
   SMALL HELPERS
----------------------------*/
function badge(string $label, string $type=''): string {
  $typeClass = $type ? ' '.$type : '';
  // Ensure label is safe for HTML content inside the badge
  return '<span class="badge'.$typeClass.'">'.e($label).'</span>';
}
function status_chip(?string $s): string {
  $s = normalize_status($s);
  $map = [
    'Received'=>'info',
    'In Repair'=>'progress',
    'Billed'=>'warning',
    'Shipped'=>'accent',
    'Delivered'=>'success',
    'Pickup In Progress'=>'info',
    'Device Received'=>'info',
    'At Warehouse'=>'info',
    'Onsite In Progress'=>'info',
    'Onsite Repair Started'=>'info',
    'Onsite Completed'=>'info',
    'Rejected'=>'muted',
    'Cancelled'=>'muted',
  ];
  $cls = $map[$s] ?? 'muted';
  $label = $s ?: 'Unknown';
  // Ensure label is safe for HTML content inside the chip
  return '<span class="chip '.$cls.'">'.e($label).'</span>';
}
function desk_link(string $desk, int $reqId, string $ticket): string {
  $map = [
    'Registration' => 'staff/registration.php',
    'Repair'       => 'staff/repair.php',
    'Billing'      => 'staff/billing.php',
    'Shipping'     => 'staff/shipping.php',
  ];
  $path = $map[$desk] ?? 'staff/registration.php';
  // Use e() for ticket in URL query string
  $url = base_url($path.'?id='.$reqId.'&ticket='.urlencode($ticket));
  // Ensure URL and text are safe
  return '<a class="btn outline" href="'.e($url).'">Open</a>';
}
function row_url(int $id, string $ticket, string $status): string {
  $tab = tab_for_status($status);
  // Use e() for ticket in URL query string
  $url = base_url('staff/registration.php?ticket='.urlencode($ticket).'&id='.$id.'&tab='.$tab);
  return e($url); // Ensure URL itself is escaped for data attribute
}

?>
<!doctype html>
<html lang="en" data-theme="light">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Staff Dashboard — NexusFix</title>
  <link rel="stylesheet" href="../../assets/css/styles.css">
  <style>
    :root {
      --bg: #f8fafc;
      --fg: #0f172a;
      --muted: #64748b;
      --line: #e2e8f0;
      --card: #ffffff;
      --radius: 12px;
      --brand: #2563eb;
      --brand-2: #0ea5e9;
      --ok: #16a34a;
      --warn: #f59e0b;
      --danger: #dc2626;
      --info: #0284c7;
      --progress: #7c3aed;
      --accent: #0ea5e9;
      --focus-ring: #94a3b8;
      --shadow-sm: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
      --shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px -1px rgba(0, 0, 0, 0.1);
      --transition: all 0.2s ease;
    }

    @media (prefers-color-scheme: dark) {
      :root {
        --bg: #0b1220;
        --fg: #e5e7eb;
        --muted: #94a3b8;
        --line: #1f2937;
        --card: #111827;
        --focus-ring: #475569;
        --shadow-sm: 0 1px 2px 0 rgba(0, 0, 0, 0.2);
        --shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.3), 0 1px 2px -1px rgba(0, 0, 0, 0.2);
      }
    }

    [data-theme="dark"] {
      --bg: #0b1220;
      --fg: #e5e7eb;
      --muted: #94a3b8;
      --line: #1f2937;
      --card: #111827;
      --focus-ring: #475569;
      --shadow-sm: 0 1px 2px 0 rgba(0, 0, 0, 0.2);
      --shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.3), 0 1px 2px -1px rgba(0, 0, 0, 0.2);
    }

    body {
      background: var(--bg);
      color: var(--fg);
      font-family: system-ui, -apple-system, Segoe UI, Roboto, sans-serif;
      margin: 0;
      padding: 0;
      transition: background-color 0.3s, color 0.3s;
    }
    main {
      max-width: 1200px;
      margin: 24px auto;
      padding: 0 16px;
    }
    h2 {
      margin: 8px 0 16px 0;
      font-size: 1.5rem;
      font-weight: 600;
    }

    .split {
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 12px;
      flex-wrap: wrap;
    }
    .tools {
      display: flex;
      gap: 8px;
      flex-wrap: wrap;
      align-items: center;
    }
    .tools .btn {
      text-decoration: none;
      display: inline-flex;
      align-items: center;
      gap: 6px;
    }
    .tools .btn svg {
      width: 16px;
      height: 16px;
    }

    .kpis {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
      gap: 12px;
      margin: 14px 0;
    }
    .kpi {
      background: var(--card);
      border: 1px solid var(--line);
      border-radius: var(--radius);
      padding: 16px;
      position: relative;
      overflow: hidden;
      box-shadow: var(--shadow-sm);
      transition: var(--transition), transform 0.1s ease;
    }
    .kpi:hover {
      transform: translateY(-2px);
      box-shadow: var(--shadow);
    }
    .kpi:focus-within, .kpi:focus {
      outline: 2px solid var(--focus-ring);
      outline-offset: 2px;
      z-index: 10;
    }
    .kpi::after {
      content: "";
      position: absolute;
      inset: auto -20% -40% auto;
      width: 140%;
      height: 140%;
      background: linear-gradient(90deg, rgba(37, 99, 235, 0.05), rgba(14, 165, 233, 0.05));
      transform: rotate(8deg);
      pointer-events: none;
      z-index: 0;
    }
    .kpi > * {
      position: relative;
      z-index: 1;
    }
    .kpi .label {
      font-size: 0.85rem;
      color: var(--muted);
      margin-bottom: 4px;
      font-weight: 500;
    }
    .kpi .value {
      font-size: 1.8rem;
      font-weight: 700;
      margin: 0 0 6px 0;
      color: var(--brand);
    }
    .kpi .sub {
      font-size: 0.8rem;
      color: var(--muted);
    }

    .board {
      display: grid;
      grid-template-columns: 2fr 1fr;
      gap: 16px;
      margin-top: 16px;
    }
    .card {
      background: var(--card);
      border: 1px solid var(--line);
      border-radius: var(--radius);
      padding: 16px;
      box-shadow: var(--shadow-sm);
    }
    .card-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 12px;
      flex-wrap: wrap;
      gap: 10px;
    }
    .card-header h3 {
      margin: 0;
      font-size: 1.2rem;
      font-weight: 600;
    }
    .grid {
      display: grid;
      gap: 12px;
    }
    .grid-2 {
      grid-template-columns: 1fr 1fr;
    }
    .grid-3 {
      grid-template-columns: repeat(3, 1fr);
    }
    .tiny {
      color: var(--muted);
      font-size: 0.85rem;
    }

    .chips {
      display: flex;
      flex-wrap: wrap;
      gap: 6px;
    }
    .chip {
      border: 1px solid var(--line);
      border-radius: 999px;
      padding: 4px 10px;
      font-size: 0.8rem;
      font-weight: 500;
      white-space: nowrap;
    }
    .chip.info {
      background: #e0f2fe;
      color: #075985;
    }
    .chip.progress {
      background: #ede9fe;
      color: #5b21b6;
    }
    .chip.warning {
      background: #fef3c7;
      color: #92400e;
    }
    .chip.success {
      background: #dcfce7;
      color: #166534;
    }
    .chip.accent {
      background: #dbeafe;
      color: #1e3a8a;
    }
    .chip.muted {
      background: #e5e7eb;
      color: #374151;
    }
    @media (prefers-color-scheme: dark) {
      .chip.info {
        background: #0b2a3a;
        color: #7dd3fc;
      }
      .chip.progress {
        background: #1b1030;
        color: #c4b5fd;
      }
      .chip.warning {
        background: #2b1f02;
        color: #fcd34d;
      }
      .chip.success {
        background: #0f2b18;
        color: #86efac;
      }
      .chip.accent {
        background: #0a1c33;
        color: #93c5fd;
      }
      .chip.muted {
        background: #1f2937;
        color: #cbd5e1;
      }
    }
    [data-theme="dark"] .chip.info {
      background: #0b2a3a;
      color: #7dd3fc;
    }
    [data-theme="dark"] .chip.progress {
      background: #1b1030;
      color: #c4b5fd;
    }
    [data-theme="dark"] .chip.warning {
      background: #2b1f02;
      color: #fcd34d;
    }
    [data-theme="dark"] .chip.success {
      background: #0f2b18;
      color: #86efac;
    }
    [data-theme="dark"] .chip.accent {
      background: #0a1c33;
      color: #93c5fd;
    }
    [data-theme="dark"] .chip.muted {
      background: #1f2937;
      color: #cbd5e1;
    }

    .badge {
      display: inline-block;
      border: 1px solid var(--line);
      border-radius: 6px;
      padding: 2px 8px;
      font-size: 0.75rem;
      font-weight: 500;
    }
    .badge.brand {
      border-color: transparent;
      background: linear-gradient(90deg, var(--brand), var(--brand-2));
      color: #fff;
    }
    .badge.warn {
      background: #fef2f2;
      color: #991b1b;
      border-color: #fecaca;
    }
    .badge.soft {
      background: #f1f5f9;
      color: #334155;
    }
    @media (prefers-color-scheme: dark) {
      .badge.warn {
        background: #450a0a;
        color: #fecaca;
        border-color: #991b1b;
      }
      .badge.soft {
        background: #1e293b;
        color: #cbd5e1;
      }
    }
    [data-theme="dark"] .badge.warn {
      background: #450a0a;
      color: #fecaca;
      border-color: #991b1b;
    }
    [data-theme="dark"] .badge.soft {
      background: #1e293b;
      color: #cbd5e1;
    }

    .filters {
      display: flex;
      flex-wrap: wrap;
      gap: 10px;
      align-items: center;
      padding: 10px 0;
      border-bottom: 1px solid var(--line);
      margin-bottom: 12px;
    }
    .filters > * {
      flex: 1;
      min-width: 150px;
    }
    .filters input[type="text"],
    .filters select {
      width: 100%;
      padding: 0.5rem 0.7rem;
      border-radius: 8px;
      border: 1px solid var(--line);
      background: var(--card);
      color: var(--fg);
      font-size: 0.9rem;
    }
    .filters input[type="text"]:focus,
    .filters select:focus {
      outline: 2px solid var(--focus-ring);
      outline-offset: 0;
      border-color: var(--brand);
    }
    .filter-active {
      border-color: var(--brand) !important;
      box-shadow: 0 0 0 1px var(--brand);
    }

    .btn {
      display: inline-block;
      padding: 0.55rem 0.9rem;
      border-radius: 8px;
      border: 1px solid var(--line);
      background: var(--card);
      color: var(--fg);
      text-decoration: none;
      font-size: 0.9rem;
      font-weight: 500;
      cursor: pointer;
      transition: var(--transition);
      box-shadow: var(--shadow-sm);
    }
    .btn:hover {
      background-color: rgba(0,0,0,0.03);
      transform: translateY(-1px);
    }
    .btn:focus {
      outline: 2px solid var(--focus-ring);
      outline-offset: 0;
    }
    .btn:active {
      transform: translateY(0);
      box-shadow: var(--shadow-sm);
    }
    .btn.primary {
      background: linear-gradient(90deg, var(--brand), var(--brand-2));
      color: #fff;
      border-color: transparent;
    }
    .btn.primary:hover {
      background: linear-gradient(90deg, #1d4ed8, #0284c7);
    }
    .btn.outline {
      background: transparent;
    }
    .btn.outline:hover {
      background-color: rgba(37, 99, 235, 0.05);
    }
    .btn.small {
      padding: 0.3rem 0.6rem;
      font-size: 0.8rem;
    }

    .list {
      display: flex;
      flex-direction: column;
      gap: 12px;
    }
    .assignment {
      border: 1px dashed var(--line);
      border-radius: 10px;
      padding: 12px;
      transition: var(--transition);
    }
    .assignment:hover {
      background-color: rgba(0,0,0,0.02);
      border-style: solid;
    }
    @media (prefers-color-scheme: dark) {
      .assignment:hover {
        background-color: rgba(255,255,255,0.02);
      }
    }
    [data-theme="dark"] .assignment:hover {
      background-color: rgba(255,255,255,0.02);
    }
    .assignment-header {
      display: flex;
      justify-content: space-between;
      align-items: flex-start;
      gap: 8px;
      margin-bottom: 8px;
    }
    .assignment-title {
      font-weight: 600;
    }
    .assignment-details {
      display: flex;
      flex-wrap: wrap;
      gap: 15px;
      font-size: 0.85rem;
    }
    .assignment-meta {
      display: flex;
      flex-wrap: wrap;
      gap: 15px;
      margin-top: 8px;
      font-size: 0.8rem;
      color: var(--muted);
    }
    .assignment-actions {
      display: flex;
      gap: 8px;
      margin-top: 8px;
    }

    .table-container {
      overflow-x: auto;
    }
    .table {
      width: 100%;
      border-collapse: collapse;
      font-size: 0.9rem;
    }
    .table th,
    .table td {
      border-bottom: 1px solid var(--line);
      padding: 10px 8px;
      text-align: left;
    }
    .table th {
      font-weight: 600;
      color: var(--muted);
      cursor: pointer;
      position: sticky;
      top: 0;
      background: var(--card);
      z-index: 1;
      transition: background-color 0.2s;
    }
    .table th:hover {
      background-color: rgba(0,0,0,0.03);
    }
    @media (prefers-color-scheme: dark) {
      .table th:hover {
        background-color: rgba(255,255,255,0.03);
      }
    }
    [data-theme="dark"] .table th:hover {
      background-color: rgba(255,255,255,0.03);
    }
    .table th[aria-sort="ascending"]::after {
      content: " \25B2";
      font-size: 0.8em;
      color: var(--brand);
    }
    .table th[aria-sort="descending"]::after {
      content: " \25BC";
      font-size: 0.8em;
      color: var(--brand);
    }
    .table tr:hover {
      background-color: rgba(0,0,0,0.02);
    }
    @media (prefers-color-scheme: dark) {
      .table tr:hover {
        background-color: rgba(255,255,255,0.02);
      }
    }
    [data-theme="dark"] .table tr:hover {
      background-color: rgba(255,255,255,0.02);
    }
    .row-link {
      cursor: pointer;
    }
    .row-link:hover td {
      background-color: inherit;
    }

    .empty {
      padding: 24px;
      text-align: center;
      color: var(--muted);
      font-style: italic;
      border: 1px dashed var(--line);
      border-radius: 8px;
      background-color: rgba(0,0,0,0.01);
    }
    @media (prefers-color-scheme: dark) {
      .empty {
        background-color: rgba(255,255,255,0.01);
      }
    }
    [data-theme="dark"] .empty {
      background-color: rgba(255,255,255,0.01);
    }

    .legend {
      display: flex;
      gap: 10px;
      flex-wrap: wrap;
      align-items: center;
      font-size: 0.8rem;
    }
    .legend .dot {
      width: 10px;
      height: 10px;
      border-radius: 999px;
      display: inline-block;
    }

    .density-toggle {
      display: flex;
      align-items: center;
      gap: 6px;
      margin-left: auto;
    }
    .dense .table th,
    .dense .table td {
      padding: 6px;
    }
    .dense .assignment {
      padding: 8px;
    }
    .dense .kpi {
      padding: 12px;
    }
    .dense .card {
      padding: 12px;
    }

    .spinner {
      border: 2px solid var(--line);
      border-top: 2px solid var(--brand);
      border-radius: 50%;
      width: 20px;
      height: 20px;
      animation: spin 1s linear infinite;
      display: inline-block;
      vertical-align: middle;
      margin-right: 8px;
    }
    @keyframes spin {
      0% {
        transform: rotate(0deg);
      }
      100% {
        transform: rotate(360deg);
      }
    }
    .chart-placeholder {
      height: 140px;
      width: 100%;
      display: flex;
      align-items: center;
      justify-content: center;
      color: var(--muted);
      font-size: 0.9rem;
    }
    .chart-container {
      height: 140px;
      width: 100%;
      position: relative;
    }

    @media (max-width: 1000px) {
      .board {
        grid-template-columns: 1fr;
      }
      .kpis {
        grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
      }
    }
    @media (max-width: 640px) {
      .kpis {
        grid-template-columns: repeat(2, 1fr);
      }
      .grid-3 {
        grid-template-columns: 1fr;
      }
      .tools {
        width: 100%;
      }
      .split {
        flex-direction: column;
        align-items: stretch;
      }
      .card-header {
        flex-direction: column;
        align-items: stretch;
      }
    }
  </style>
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js" crossorigin="anonymous"></script>
</head>
<body>
  <?php require __DIR__.'/../../includes/header.php'; ?>

  <main>
    <div class="split">
      <h2>Welcome, <span id="user-name"><?= e($u['name']) ?></span> <span class="tiny">(<?= e(ucfirst((string)($u['role'] ?? 'Staff'))) ?>)</span></h2>
      <div class="tools">
        <a class="btn primary" href="<?= e(base_url('staff/request_new.php')) ?>">
          <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" width="16" height="16"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" /></svg>
          Create New Ticket
        </a>
        <a class="btn outline" href="<?= e(base_url('staff/registration.php')) ?>">Registration</a>
        <a class="btn outline" href="<?= e(base_url('staff/repair.php')) ?>">Repair</a>
        <a class="btn outline" href="<?= e(base_url('staff/billing.php')) ?>">Billing</a>
        <a class="btn outline" href="<?= e(base_url('staff/shipping.php')) ?>">Shipping</a>
        <?php if(($u['role'] ?? '')==='admin'): ?>
          <a class="btn outline" href="<?= e(base_url('staff/users.php')) ?>">Users</a>
        <?php endif; ?>
        <label class="btn outline density-toggle" for="density" title="Toggle compact view">
          <input id="density" type="checkbox">
          <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" width="16" height="16"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" /></svg>
        </label>
      </div>
    </div>

    <div class="kpis" role="list">
      <div class="kpi" role="listitem" tabindex="0" aria-label="Received Requests">
        <div class="label">Received</div>
        <div class="value"><?= e((string)$counts['Received']) ?></div>
        <div class="sub">Today: <?= badge((string)$today_new, 'brand') ?></div>
      </div>
      <div class="kpi" role="listitem" tabindex="0" aria-label="Requests In Repair">
        <div class="label">In Repair</div>
        <div class="value"><?= e((string)$counts['In Repair']) ?></div>
        <div class="sub">MTTR: <?= e((string)$mttr_hours) ?>h</div>
      </div>
      <div class="kpi" role="listitem" tabindex="0" aria-label="Billed Requests">
        <div class="label">Billed</div>
        <div class="value"><?= e((string)$counts['Billed']) ?></div>
        <div class="sub">
          <?= $overdue_billing > 0 ? badge("Overdue: $overdue_billing", 'warn') : badge("Overdue: $overdue_billing", 'soft') ?>
        </div>
      </div>
      <div class="kpi" role="listitem" tabindex="0" aria-label="Shipped Requests">
        <div class="label">Shipped</div>
        <div class="value"><?= e((string)$counts['Shipped']) ?></div>
        <div class="sub"><?= badge('On the way','soft') ?></div>
      </div>
      <div class="kpi" role="listitem" tabindex="0" aria-label="Delivered Requests">
        <div class="label">Delivered</div>
        <div class="value"><?= e((string)$counts['Delivered']) ?></div>
        <div class="sub"><?= badge('Completed','soft') ?></div>
      </div>
      <div class="kpi" role="listitem" tabindex="0" aria-label="Backlog">
        <div class="label">Backlog (7+ days)</div>
        <div class="value"><?= e((string)$backlog) ?></div>
        <div class="sub"><?= badge('Needs attention', $backlog>0?'warn':'soft') ?></div>
      </div>
    </div>

    <div class="board">
      <section class="card" aria-labelledby="assignmentsHeading">
        <div class="card-header">
          <h3 id="assignmentsHeading">My Assignments</h3>
          <div class="tiny">Click a row to open the ticket.</div>
        </div>
        <div class="filters" role="group" aria-label="Filters for assignments">
          <input id="as-search" type="text" placeholder="Search ticket/device/brand..." aria-label="Search assignments">
          <select id="as-desk" aria-label="Filter by desk">
            <option value="">All Desks</option>
            <option value="registration">Registration</option>
            <option value="repair">Repair</option>
            <option value="billing">Billing</option>
            <option value="shipping">Shipping</option>
          </select>
          <select id="as-status" aria-label="Filter by status">
            <option value="">All Status</option>
            <?php foreach (array_merge($kpiBuckets, ['Pickup In Progress', 'Device Received', 'At Warehouse', 'Onsite In Progress', 'Onsite Repair Started', 'Onsite Completed', 'Rejected', 'Cancelled']) as $s): ?>
              <option value="<?= e(strtolower($s)) ?>"><?= e($s) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div id="assignments" class="list" aria-live="polite">
          <?php if(!$my): ?>
            <div class="empty">No assignments found. Relax or check other queues!</div>
          <?php else: ?>
            <?php foreach($my as $m):
              $ms = normalize_status($m['status']);
              $deskLower = strtolower((string)$m['desk']);
              $statusLower = strtolower($ms);
              $textIndex = e(strtolower(trim(($m['ticket_code']??'').' '.($m['device_type']??'').' '.($m['brand']??'').' '.($m['model']??''))));
              $ticketCodeEscaped = e($m['ticket_code'] ?? 'N/A');
              $deviceTypeEscaped = e($m['device_type'] ?? '');
              $brandEscaped = e($m['brand'] ?? '');
              $modelEscaped = e($m['model'] ?? '');
              $deskEscaped = e($m['desk'] ?? '');
              $assignedAtEscaped = e($m['assigned_at'] ?? '');
              $priorityEscaped = e(ucfirst((string)($m['priority'] ?? '')));
            ?>
              <div class="assignment as-item"
                   data-desk="<?= $deskLower ?>"
                   data-status="<?= $statusLower ?>"
                   data-text="<?= $textIndex ?>">
                <div class="assignment-header">
                  <div class="assignment-title"><?= $ticketCodeEscaped ?> &mdash; <?= $deviceTypeEscaped ?></div>
                </div>
                <div class="assignment-details">
                  <?php if ($brandEscaped): ?><span><strong>Brand:</strong> <?= $brandEscaped ?></span><?php endif; ?>
                  <?php if ($modelEscaped): ?><span><strong>Model:</strong> <?= $modelEscaped ?></span><?php endif; ?>
                </div>
                <div class="assignment-meta">
                  <span><strong>Desk:</strong> <?= $deskEscaped ?></span>
                  <span><strong>Assigned:</strong> <time datetime="<?= $assignedAtEscaped ?>"><?= $assignedAtEscaped ?></time></span>
                  <span><strong>Status:</strong> <?= status_chip($ms) ?></span>
                  <span><strong>Priority:</strong> <?= $priorityEscaped ?></span>
                </div>
                <div class="assignment-actions">
                  <?= desk_link($m['desk'], (int)($m['request_id'] ?? 0), (string)($m['ticket_code'] ?? '')) ?>
                  <a class="btn outline small" href="<?= row_url((int)($m['request_id'] ?? 0), (string)($m['ticket_code'] ?? ''), $ms) ?>">History</a>
                </div>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
        <?php if($unassigned > 0): ?>
          <div class="tiny" style="margin-top:12px; padding-top:8px; border-top: 1px solid var(--line);">
            <?= badge("Unassigned: ".$unassigned, 'warn') ?> &mdash; Assign from Registration/Repair queues.
          </div>
        <?php endif; ?>
      </section>

      <aside class="grid" aria-label="Insights and Quick Actions">
        <section class="card">
          <div class="card-header">
            <h3>Request Status</h3>
            <div class="legend tiny" id="statusLegend"></div>
          </div>
          <div class="chart-container">
            <canvas id="statusChart" height="140" role="img" aria-label="Doughnut chart of request statuses"></canvas>
          </div>
        </section>

        <section class="card">
          <div class="card-header">
            <h3>Daily New (14 days)</h3>
          </div>
          <div class="chart-container">
            <canvas id="dailyChart" height="140" role="img" aria-label="Line chart of daily new requests"></canvas>
          </div>
        </section>

        <section class="card">
          <h3>Quick Actions</h3>
          <div class="grid grid-3">
            <a class="btn primary" href="<?= e(base_url('staff/request_new.php')) ?>">New Ticket</a>
            <a class="btn outline" href="<?= e(base_url('staff/registration.php')) ?>">Intake</a>
            <a class="btn outline" href="<?= e(base_url('staff/repair.php')) ?>">Repair</a>
            <a class="btn outline" href="<?= e(base_url('staff/billing.php')) ?>">Billing</a>
            <a class="btn outline" href="<?= e(base_url('staff/shipping.php')) ?>">Shipping</a>
            <?php if(($u['role'] ?? '')==='admin'): ?>
              <a class="btn outline" href="<?= e(base_url('staff/users.php')) ?>">Manage Users</a>
            <?php endif; ?>
          </div>
          <p class="tiny" style="margin-top:10px;">Tip: Press <kbd>/</kbd> to search assignments.</p>
        </section>
      </aside>
    </div>

    <section class="card" style="margin-top:16px;">
      <div class="card-header">
        <h3>Latest Requests</h3>
        <div class="tiny">Headers are sortable.</div>
      </div>
      <?php if(!$latest): ?>
        <div class="empty">No recent requests found.</div>
      <?php else: ?>
        <div class="table-container">
          <table class="table" id="latestTable" aria-describedby="latestCaption">
            <caption id="latestCaption" class="sr-only">Most recent 12 tickets</caption>
            <thead>
              <tr>
                <th scope="col" data-key="ticket_code" tabindex="0" role="button" aria-sort="none">Ticket</th>
                <th scope="col" data-key="device" tabindex="0" role="button" aria-sort="none">Device</th>
                <th scope="col" data-key="status" tabindex="0" role="button" aria-sort="none">Status</th>
                <th scope="col" data-key="created_at" tabindex="0" role="button" aria-sort="none">Created</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach($latest as $r):
                $rawTicketCode = $r['ticket_code'] ?? '';
                $rawDeviceType = $r['device_type'] ?? '';
                $rawBrand = $r['brand'] ?? '';
                $rawModel = $r['model'] ?? '';
                $rawStatus = $r['status'] ?? '';
                $rawCreatedAt = $r['created_at'] ?? '';

                $ticketCodeEscaped = e($rawTicketCode);
                $deviceEscaped = e(trim($rawDeviceType.' '.($rawBrand? '· '.$rawBrand:'').' '.($rawModel? '· '.$rawModel:'')));
                $statusNormalized = normalize_status($rawStatus);
                $statusEscaped = e($statusNormalized);
                $createdAtEscaped = e($rawCreatedAt);

                $safeRowUrl = row_url((int)($r['id'] ?? 0), $rawTicketCode, $statusNormalized);
              ?>
              <tr class="row-link"
                  data-href="<?= $safeRowUrl ?>"
                  data-ticket_code="<?= e(strtolower($rawTicketCode)) ?>"
                  data-device="<?= e(strtolower($deviceEscaped)) ?>"
                  data-status="<?= e(strtolower($statusEscaped)) ?>"
                  data-created_at="<?= $createdAtEscaped ?>">
                <td><?= $ticketCodeEscaped ?></td>
                <td><?= $deviceEscaped ?></td>
                <td><?= status_chip($statusNormalized) ?></td>
                <td class="tiny"><?= $createdAtEscaped ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </section>
  </main>

  <script>
    window.NexusFixApp = window.NexusFixApp || {};
    (function(App) {
      'use strict';

      App.Utils = {
        formatDateTime: function(isoString) {
          if (!isoString) return 'N/A';
          const date = new Date(isoString);
          if (isNaN(date)) return isoString;
          return new Intl.DateTimeFormat(undefined, {
            year: 'numeric', month: 'short', day: '2-digit',
            hour: '2-digit', minute: '2-digit'
          }).format(date);
        },
        debounce: function(func, delay) {
          let timeoutId;
          return function (...args) {
            clearTimeout(timeoutId);
            timeoutId = setTimeout(() => func.apply(this, args), delay);
          };
        }
      };

      App.Dashboard = {
        init: function() {
          this.initClickableRows();
          this.initSortableTable();
          this.initAssignmentFilters();
          this.initDensityToggle();
          this.initKeyboardShortcuts();
          this.localizeDates();
          App.Charts.init();
        },
        initClickableRows: function() {
          document.addEventListener('click', function(e) {
            const tr = e.target.closest('.row-link');
            if (tr && tr.dataset.href) {
              window.location.href = tr.dataset.href;
            }
          });
          document.addEventListener('keydown', function(e) {
            const tr = e.target.closest('.row-link');
            if (tr && tr.dataset.href && (e.key === 'Enter' || e.key === ' ')) {
              e.preventDefault();
              window.location.href = tr.dataset.href;
            }
          });
        },
        initSortableTable: function() {
          const table = document.getElementById('latestTable');
          if (!table) return;
          const ths = Array.from(table.querySelectorAll('th'));
          const tbody = table.querySelector('tbody');
          let sortKey = null, sortDir = 'asc';

          const getVal = (tr, key) => (tr.dataset[key] || '').toString();

          const applySort = (key) => {
            const rows = Array.from(tbody.querySelectorAll('tr'));
            const dir = (sortKey === key && sortDir === 'asc') ? 'desc' : 'asc';
            rows.sort((a, b) => {
              const av = getVal(a, key), bv = getVal(b, key);
              if (key === 'created_at') {
                const dateA = new Date(av);
                const dateB = new Date(bv);
                if (isNaN(dateA) || isNaN(dateB)) return 0;
                return dir === 'asc' ? dateA - dateB : dateB - dateA;
              }
              return dir === 'asc' ? av.localeCompare(bv) : bv.localeCompare(av);
            });
            const frag = document.createDocumentFragment();
            rows.forEach(r => frag.appendChild(r));
            tbody.appendChild(frag);

            ths.forEach(th => th.setAttribute('aria-sort', 'none'));
            const active = ths.find(t => t.dataset.key === key);
            if (active) {
              active.setAttribute('aria-sort', dir === 'asc' ? 'ascending' : 'descending');
            }
            sortKey = key;
            sortDir = dir;
          };

          ths.forEach(th => {
            th.addEventListener('click', () => applySort(th.dataset.key));
            th.addEventListener('keydown', (e) => {
              if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                applySort(th.dataset.key);
              }
            });
          });
        },
        initAssignmentFilters: function() {
          const q = document.getElementById('as-search');
          const fDesk = document.getElementById('as-desk');
          const fStatus = document.getElementById('as-status');
          const items = Array.from(document.querySelectorAll('.as-item'));
          const KEY = 'nf_assign_filters_v2';

          const highlightActiveFilters = () => {
            q.classList.toggle('filter-active', q.value.trim() !== '');
            fDesk.classList.toggle('filter-active', fDesk.value !== '');
            fStatus.classList.toggle('filter-active', fStatus.value !== '');
          };

          const apply = () => {
            const text = (q.value || '').toLowerCase().trim();
            const desk = (fDesk.value || '').toLowerCase();
            const status = (fStatus.value || '').toLowerCase();

            let anyVisible = false;
            for (const el of items) {
              const t = el.dataset.text || '';
              const d = el.dataset.desk || '';
              const s = el.dataset.status || '';
              const matchesText = text === '' || t.includes(text);
              const matchesDesk = desk === '' || d === desk;
              const matchesStatus = status === '' || s === status;
              const visible = matchesText && matchesDesk && matchesStatus;
              el.style.display = visible ? '' : 'none';
              if (visible) anyVisible = true;
            }

            const emptyState = document.querySelector('#assignments .empty');
            if (emptyState) {
              emptyState.style.display = anyVisible ? 'none' : 'block';
              emptyState.textContent = text || desk || status ?
                'No assignments match your filters.' :
                'No assignments found. Relax or check other queues!';
            }

            localStorage.setItem(KEY, JSON.stringify({text, desk, status}));
            highlightActiveFilters();
          };

          try {
            const saved = JSON.parse(localStorage.getItem(KEY) || '{}');
            if (saved.text !== undefined) q.value = saved.text;
            if (saved.desk !== undefined) fDesk.value = saved.desk;
            if (saved.status !== undefined) fStatus.value = saved.status;
          } catch(e) {
            console.warn('Could not restore assignment filters:', e);
          }

          q.addEventListener('input', App.Utils.debounce(apply, 300));
          fDesk.addEventListener('change', apply);
          fStatus.addEventListener('change', apply);

          apply();
        },
        initDensityToggle: function() {
          const body = document.body;
          const cb = document.getElementById('density');
          if (!cb) return;
          const KEY = 'nf_density_dense';
          const saved = localStorage.getItem(KEY) === '1';
          if (saved) {
            body.classList.add('dense');
            cb.checked = true;
          }
          cb.addEventListener('change', function() {
            body.classList.toggle('dense', cb.checked);
            localStorage.setItem(KEY, cb.checked ? '1' : '0');
          });
        },
        initKeyboardShortcuts: function() {
          window.addEventListener('keydown', function(e) {
            if (e.key === '/' && e.target.tagName !== 'INPUT' && e.target.tagName !== 'TEXTAREA' && !e.metaKey && !e.ctrlKey) {
              e.preventDefault();
              const searchInput = document.getElementById('as-search');
              if (searchInput) {
                searchInput.focus();
                searchInput.select();
              }
            }
          });
        },
        localizeDates: function() {
          // Handled server-side
        }
      };

      App.Charts = {
        statusChart: null,
        dailyChart: null,
        init: function() {
          if (!window.Chart) {
            console.error('Chart.js library not loaded.');
            this.showChartError('statusChart');
            this.showChartError('dailyChart');
            return;
          }

          Chart.defaults.font.family = "'system-ui', '-apple-system', 'Segoe UI', 'Roboto', sans-serif";
          Chart.defaults.animation.duration = 500;

          this.initStatusChart();
          this.initDailyChart();
        },
        showChartError: function(canvasId) {
          const canvas = document.getElementById(canvasId);
          if (canvas) {
            const parent = canvas.parentNode;
            parent.innerHTML = '<span style="color:var(--danger);">⚠️ Chart failed to load.</span>';
          }
        },
        initStatusChart: function() {
          const rawData = <?= json_encode($statusDist, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
          if (!Array.isArray(rawData) || rawData.length === 0) {
            this.showChartError('statusChart');
            return;
          }

          const canvas = document.getElementById('statusChart');
          if (!canvas) {
            console.error('Status chart canvas not found.');
            return;
          }

          // Destroy existing chart if it exists
          if (this.statusChart) {
            this.statusChart.destroy();
          }

          const labels = rawData.map(d => d.label);
          const values = rawData.map(d => d.value);

          this.statusChart = new Chart(canvas.getContext('2d'), {
            type: 'doughnut',
            data: {
              labels: labels,
              datasets: [{
                data: values,
                backgroundColor: [
                  '#4e79a7', '#f28e2c', '#e15759', '#76b7b2', '#59a14f',
                  '#edc949', '#af7aa1', '#ff9da7', '#9c755f', '#bab0ab'
                ]
              }]
            },
            options: {
              responsive: true,
              maintainAspectRatio: false,
              plugins: {
                legend: { display: false },
                tooltip: {
                  callbacks: {
                    label: function(context) {
                      const label = context.label || '';
                      const value = context.parsed || 0;
                      const total = context.dataset.data.reduce((a, b) => a + b, 0);
                      const percentage = total > 0 ? Math.round((value / total) * 100) : 0;
                      return `${label}: ${value} (${percentage}%)`;
                    }
                  }
                }
              },
              cutout: '60%',
              animation: {
                animateRotate: true,
                animateScale: false
              }
            }
          });

          this.populateStatusLegend(labels, values);
        },
        populateStatusLegend: function(labels, values) {
          const legendContainer = document.getElementById('statusLegend');
          if (!legendContainer || !this.statusChart) return;

          legendContainer.innerHTML = '';
          const total = values.reduce((a, b) => a + b, 0);
          if (total === 0) return;

          const backgroundColors = this.statusChart.data.datasets[0].backgroundColor;

          labels.forEach((label, i) => {
            const value = values[i];
            if (value === 0) return;
            const percentage = Math.round((value / total) * 100);
            const color = backgroundColors[i] || '#cccccc';

            const item = document.createElement('div');
            item.className = 'legend-item';
            item.style.display = 'flex';
            item.style.alignItems = 'center';
            item.style.gap = '4px';
            item.style.fontSize = '0.8rem';
            item.innerHTML = `<span class="dot" style="background-color:${color}; width:10px; height:10px; border-radius:50%; display:inline-block;"></span> ${label} (${value})`;
            item.title = `${percentage}% of total`;
            legendContainer.appendChild(item);
          });
        },
        initDailyChart: function() {
          const rawLabels = <?= json_encode($labels, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
          const rawValues = <?= json_encode(array_values($dailyNew), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

          if (!Array.isArray(rawLabels) || !Array.isArray(rawValues) || rawLabels.length === 0) {
            this.showChartError('dailyChart');
            return;
          }

          const canvas = document.getElementById('dailyChart');
          if (!canvas) {
            console.error('Daily chart canvas not found.');
            return;
          }

          // Destroy existing chart if it exists
          if (this.dailyChart) {
            this.dailyChart.destroy();
          }

          this.dailyChart = new Chart(canvas.getContext('2d'), {
            type: 'line',
            data: {
              labels: rawLabels,
              datasets: [{
                label: 'New Requests',
                data: rawValues,
                borderColor: '#2563eb',
                backgroundColor: 'rgba(37, 99, 235, 0.1)',
                borderWidth: 2,
                pointRadius: 3,
                pointBackgroundColor: '#fff',
                pointBorderWidth: 1,
                pointBorderColor: '#2563eb',
                tension: 0.3,
                fill: true
              }]
            },
            options: {
              responsive: true,
              maintainAspectRatio: false,
              plugins: {
                legend: { display: false },
                tooltip: {
                  mode: 'index',
                  intersect: false,
                  callbacks: {
                    title: function(context) {
                      const rawDate = context[0].label;
                      if (!rawDate) return '';
                      const parts = rawDate.split('-');
                      if (parts.length === 3) {
                        const date = new Date(parts[0], parts[1]-1, parts[2]);
                        if (!isNaN(date)) {
                          return date.toLocaleDateString(undefined, { year: 'numeric', month: 'long', day: 'numeric' });
                        }
                      }
                      return rawDate;
                    }
                  }
                }
              },
              scales: {
                x: {
                  type: 'category',
                  grid: { display: false },
                  ticks: {
                    maxRotation: 0,
                    autoSkip: true,
                    maxTicksLimit: 8
                  }
                },
                y: {
                  beginAtZero: true,
                  ticks: {
                    stepSize: 1
                  },
                  grid: {
                    color: 'rgba(0, 0, 0, 0.05)'
                  }
                }
              },
              interaction: {
                mode: 'nearest',
                axis: 'x',
                intersect: false
              }
            }
          });
        }
      };

      document.addEventListener('DOMContentLoaded', function() {
        try {
          App.Dashboard.init();
        } catch (e) {
          console.error("Error initializing NexusFix Dashboard:", e);
        }
      });
    })(window.NexusFixApp);
  </script>
</body>
</html>