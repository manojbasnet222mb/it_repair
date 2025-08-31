<?php
// /htdocs/it_repair/public/staff/dashboard.php
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
   KPI / COUNTS
----------------------------*/
$counts = array_fill_keys($kpiBuckets, 0);

// status distribution (normalized)
$rows = $pdo->query("
  SELECT COALESCE(NULLIF(status,''),'Received') AS s, COUNT(*) c
  FROM repair_requests
  GROUP BY COALESCE(NULLIF(status,''),'Received')
")->fetchAll();

foreach ($rows as $r) {
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
$mttr_hours = (float)$pdo->query("
  SELECT AVG(TIMESTAMPDIFF(HOUR, rr.created_at, h.changed_at)) AS mttr
  FROM repair_requests rr
  JOIN request_status_history h 
    ON h.request_id = rr.id AND h.status='In Repair'
")->fetchColumn();
$mttr_hours = $mttr_hours ? round($mttr_hours, 1) : 0.0;

// Backlog: open tickets older than 7 days (not Delivered/Cancelled)
$backlog = (int)$pdo->query("
  SELECT COUNT(*) FROM repair_requests
  WHERE COALESCE(NULLIF(status,''),'Received') NOT IN ('Delivered','Cancelled')
    AND created_at < (NOW() - INTERVAL 7 DAY)
")->fetchColumn();

/* ---------------------------
   CHART DATA
----------------------------*/
$statusDist = [];
foreach ($kpiBuckets as $s) $statusDist[] = ['label'=>$s, 'value'=>$counts[$s]];

// Daily new requests (last 14 days)
$dailyNew = array_fill(0, 14, 0);
$labels = [];
for ($i=13; $i>=0; $i--) $labels[] = date('Y-m-d', strtotime("-$i day"));
$stmt = $pdo->query("
  SELECT DATE(created_at) d, COUNT(*) c
  FROM repair_requests
  WHERE created_at >= (CURDATE() - INTERVAL 13 DAY)
  GROUP BY DATE(created_at)
  ORDER BY d
");
while ($r = $stmt->fetch()) {
  $idx = array_search($r['d'], $labels, true);
  if ($idx !== false) $dailyNew[$idx] = (int)$r['c'];
}

/* ---------------------------
   MY ASSIGNMENTS
----------------------------*/
$stmt = $pdo->prepare("
  SELECT ra.*, rr.id AS request_id, rr.ticket_code, rr.device_type, rr.brand, rr.model, 
         COALESCE(NULLIF(rr.status,''),'Received') AS status, rr.priority, rr.created_at
  FROM request_assignments ra
  JOIN repair_requests rr ON rr.id = ra.request_id
  WHERE ra.assigned_to = ?
    AND ra.desk IN ('Registration','Repair','Billing','Shipping')
  ORDER BY ra.assigned_at DESC
  LIMIT 40
");
$stmt->execute([$u['id']]);
$my = $stmt->fetchAll();

/* ---------------------------
   LATEST REQUESTS (sortable table)
----------------------------*/
$latest = $pdo->query("
  SELECT rr.id, rr.ticket_code, rr.device_type, rr.brand, rr.model, 
         COALESCE(NULLIF(rr.status,''),'Received') AS status, rr.created_at
  FROM repair_requests rr
  ORDER BY rr.id DESC
  LIMIT 12
")->fetchAll();

/* ---------------------------
   SMALL HELPERS
----------------------------*/
function badge(string $label, string $type=''): string {
  $typeClass = $type ? ' '.$type : '';
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
  $url = base_url($path.'?id='.$reqId.'&ticket='.urlencode($ticket));
  return '<a class="btn outline" href="'.e($url).'">Open</a>';
}
function row_url(int $id, string $ticket, string $status): string {
  $tab = tab_for_status($status);
  $url = base_url('staff/registration.php?ticket='.urlencode($ticket).'&id='.$id.'&tab='.$tab);
  return $url;
}

?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Staff Panel — NexusFix</title>
  <link rel="stylesheet" href="../../assets/css/styles.css">
  <style>
    :root{
      --bg:#f8fafc; --fg:#0f172a; --muted:#64748b; --line:#e2e8f0;
      --card:#ffffff; --radius:12px;
      --brand:#2563eb; --brand-2:#0ea5e9;
      --ok:#16a34a; --warn:#f59e0b; --danger:#dc2626; --info:#0284c7;
      --progress:#7c3aed; --accent:#0ea5e9;
      --focus:#94a3b8;
    }
    @media(prefers-color-scheme:dark){
      :root{
        --bg:#0b1220; --fg:#e5e7eb; --muted:#94a3b8; --line:#1f2937;
        --card:#111827; --focus:#475569;
      }
    }
    body{background:var(--bg); color:var(--fg); font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif}
    main{max-width:1200px; margin:24px auto; padding:0 16px}
    h2{margin:8px 0 16px 0}

    /* KPIs */
    .kpis{display:grid; grid-template-columns:repeat(6,1fr); gap:12px; margin:14px 0}
    .kpi{
      background:var(--card);
      border:1px solid var(--line);
      border-radius:var(--radius);
      padding:14px;
      position:relative;
      overflow:hidden;
    }
    .kpi:focus-within, .kpi:focus { outline:2px solid var(--focus); outline-offset:2px; }
    .kpi::after{
      content:"";
      position:absolute; inset:auto -20% -40% auto;
      width:140%; height:140%;
      background:linear-gradient(90deg,rgba(37,99,235,.05),rgba(14,165,233,.05));
      transform:rotate(8deg);
      pointer-events:none;
    }
    .kpi .label{font-size:.9rem; color:var(--muted)}
    .kpi .value{font-size:1.8rem; font-weight:700; margin-top:6px}
    .kpi .sub{font-size:.85rem; color:var(--muted); margin-top:4px}

    .board{display:grid; grid-template-columns:2fr 1fr; gap:14px; margin-top:16px}
    .card{background:var(--card); border:1px solid var(--line); border-radius:var(--radius); padding:14px}
    .card h3{margin:0 0 10px}
    .grid{display:grid; gap:10px}
    .grid-2{grid-template-columns:1fr 1fr}
    .grid-3{grid-template-columns:repeat(3,1fr)}
    .tiny{color:var(--muted); font-size:.9rem}

    .chips{display:flex; flex-wrap:wrap; gap:6px}
    .chip{border:1px solid var(--line); border-radius:999px; padding:4px 10px; font-size:.85rem}
    .chip.info{background:#e0f2fe; color:#075985}
    .chip.progress{background:#ede9fe; color:#5b21b6}
    .chip.warning{background:#fef3c7; color:#92400e}
    .chip.success{background:#dcfce7; color:#166534}
    .chip.accent{background:#dbeafe; color:#1e3a8a}
    .chip.muted{background:#e5e7eb; color:#374151}
    @media(prefers-color-scheme:dark){
      .chip.info{background:#0b2a3a; color:#7dd3fc}
      .chip.progress{background:#1b1030; color:#c4b5fd}
      .chip.warning{background:#2b1f02; color:#fcd34d}
      .chip.success{background:#0f2b18; color:#86efac}
      .chip.accent{background:#0a1c33; color:#93c5fd}
      .chip.muted{background:#1f2937; color:#cbd5e1}
    }

    .badge{display:inline-block; border:1px solid var(--line); border-radius:6px; padding:2px 8px; font-size:.8rem}
    .badge.brand{border-color:transparent; background:linear-gradient(90deg,var(--brand),var(--brand-2)); color:#fff}
    .badge.warn{background:#fef2f2; color:#991b1b; border-color:#fecaca}
    .badge.soft{background:#f1f5f9; color:#334155}

    .flex{display:flex; align-items:center; gap:10px; flex-wrap:wrap}
    .tools{display:flex; gap:8px; flex-wrap:wrap}
    input[type="text"], select{
      padding:.55rem .7rem; border-radius:8px; border:1px solid var(--line);
      background:transparent; color:var(--fg); min-width:180px;
    }
    .btn{display:inline-block; padding:.55rem .9rem; border-radius:8px; border:1px solid var(--line); text-decoration:none}
    .btn.primary{background:linear-gradient(90deg,var(--brand),var(--brand-2)); color:#fff; border-color:transparent}
    .btn.outline{background:transparent}
    .btn:focus { outline:2px solid var(--focus); outline-offset:2px; }

    .list{display:grid; gap:10px}
    .assignment{border:1px dashed var(--line); border-radius:10px; padding:10px}
    .split{display:flex; justify-content:space-between; align-items:center; gap:8px}

    .table{width:100%; border-collapse:collapse}
    .table th,.table td{border-bottom:1px solid var(--line); padding:8px; text-align:left}
    .table th{font-weight:600; color:var(--muted); cursor:pointer; position:sticky; top:0; background:var(--card); z-index:1}
    .table tr:hover{background:rgba(2,8,23,.03)}
    .table th[aria-sort="ascending"]::after{content:" ▲"; font-size:.8em}
    .table th[aria-sort="descending"]::after{content:" ▼"; font-size:.8em}

    .empty{padding:14px; color:var(--muted)}
    .row-link{cursor:pointer}
    .legend{display:flex; gap:8px; flex-wrap:wrap}
    .legend .dot{width:10px; height:10px; border-radius:999px; display:inline-block}

    .density-toggle{margin-left:auto}
    .dense .table th, .dense .table td{padding:6px}
    .dense .assignment{padding:8px}
    .dense .kpi{padding:12px}

    @media(max-width:1000px){ .board{grid-template-columns:1fr} .kpis{grid-template-columns:repeat(3,1fr)} }
    @media(max-width:640px){ .kpis{grid-template-columns:repeat(2,1fr)} .grid-3{grid-template-columns:1fr} }
  </style>
  <!-- Chart.js (defer) -->
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js" defer></script>
</head>
<body>
  <?php require __DIR__.'/../../includes/header.php'; ?>

  <main>
    <div class="split" style="gap:12px">
      <h2>Welcome, <?= e($u['name']) ?> <span class="tiny">(<?= e(ucfirst((string)$u['role'])) ?>)</span></h2>
      <div class="tools">
        <a class="btn primary" href="<?= e(base_url('staff/request_new.php')) ?>">Create New Ticket</a>
        <a class="btn outline" href="<?= e(base_url('staff/registration.php')) ?>">Registration Desk</a>
        <a class="btn outline" href <?= '"'.e(base_url('staff/repair.php')).'"' ?>>Repair Desk</a>
        <a class="btn outline" href <?= '"'.e(base_url('staff/billing.php')).'"' ?>>Billing Desk</a>
        <a class="btn outline" href <?= '"'.e(base_url('staff/shipping.php')).'"' ?>>Shipping Desk</a>
        <?php if(($u['role'] ?? '')==='admin'): ?>
          <a class="btn outline" href="<?= e(base_url('staff/users.php')) ?>">Admin: Users</a>
        <?php endif; ?>
        <label class="btn outline density-toggle" for="density">
          <input id="density" type="checkbox" style="vertical-align:middle; margin-right:6px"> Compact
        </label>
      </div>
    </div>

    <!-- KPI strip -->
    <div class="kpis" role="list">
      <div class="kpi" role="listitem" tabindex="0" aria-label="Received">
        <div class="label">Received</div>
        <div class="value"><?= e($counts['Received']) ?></div>
        <div class="sub">Today: <?= badge((string)$today_new, 'brand') ?></div>
      </div>
      <div class="kpi" role="listitem" tabindex="0" aria-label="In Repair">
        <div class="label">In Repair</div>
        <div class="value"><?= e($counts['In Repair']) ?></div>
        <div class="sub">MTTR: <?= e($mttr_hours) ?>h</div>
      </div>
      <div class="kpi" role="listitem" tabindex="0" aria-label="Billed">
        <div class="label">Billed</div>
        <div class="value"><?= e($counts['Billed']) ?></div>
        <div class="sub">
          <?= $overdue_billing>0 ? badge("Overdue: $overdue_billing", 'warn') : badge("Overdue: $overdue_billing", 'soft') ?>
        </div>
      </div>
      <div class="kpi" role="listitem" tabindex="0" aria-label="Shipped">
        <div class="label">Shipped</div>
        <div class="value"><?= e($counts['Shipped']) ?></div>
        <div class="sub"><?= badge('On the way','soft') ?></div>
      </div>
      <div class="kpi" role="listitem" tabindex="0" aria-label="Delivered">
        <div class="label">Delivered</div>
        <div class="value"><?= e($counts['Delivered']) ?></div>
        <div class="sub"><?= badge('Completed','soft') ?></div>
      </div>
      <div class="kpi" role="listitem" tabindex="0" aria-label="Backlog">
        <div class="label">Backlog (7+ days, open)</div>
        <div class="value"><?= e($backlog) ?></div>
        <div class="sub"><?= badge('Needs attention', $backlog>0?'warn':'soft') ?></div>
      </div>
    </div>

    <div class="board">
      <!-- Left: My Assignments -->
      <section class="card" aria-labelledby="assignmentsHeading">
        <div class="split">
          <h3 id="assignmentsHeading">My Assignments</h3>
          <div class="flex" role="group" aria-label="Filters for assignments">
            <input id="as-search" type="text" placeholder="Search ticket/brand/model/device…" aria-label="Search assignments">
            <select id="as-desk" aria-label="Filter by desk">
              <option value="">All desks</option>
              <option value="Registration">Registration</option>
              <option value="Repair">Repair</option>
              <option value="Billing">Billing</option>
              <option value="Shipping">Shipping</option>
            </select>
            <select id="as-status" aria-label="Filter by status">
              <option value="">All status</option>
              <?php foreach ($kpiBuckets as $s): ?>
                <option value="<?= e($s) ?>"><?= e($s) ?></option>
              <?php endforeach; ?>
              <option value="Pickup In Progress">Pickup In Progress</option>
              <option value="Device Received">Device Received</option>
              <option value="At Warehouse">At Warehouse</option>
              <option value="Onsite In Progress">Onsite In Progress</option>
              <option value="Onsite Repair Started">Onsite Repair Started</option>
              <option value="Onsite Completed">Onsite Completed</option>
              <option value="Rejected">Rejected</option>
              <option value="Cancelled">Cancelled</option>
            </select>
          </div>
        </div>

        <div id="assignments" class="list" aria-live="polite">
          <?php if(!$my): ?>
            <div class="empty">No assignments yet.</div>
          <?php else: ?>
            <?php foreach($my as $m): 
              $ms = normalize_status($m['status']);
              $deskLower = strtolower((string)$m['desk']);
              $statusLower = strtolower($ms);
              $textIndex = strtolower(trim(($m['ticket_code']??'').' '.($m['device_type']??'').' '.($m['brand']??'').' '.($m['model']??'')));
            ?>
              <div class="assignment as-item"
                   data-desk="<?= e($deskLower) ?>"
                   data-status="<?= e($statusLower) ?>"
                   data-text="<?= e($textIndex) ?>">
                <div class="split">
                  <div>
                    <strong><?= e($m['ticket_code']) ?></strong> — <?= e($m['device_type']) ?>
                    <?= $m['brand']? '· '.e($m['brand']):'' ?> <?= $m['model']? '· '.e($m['model']):'' ?>
                    <div class="tiny">
                      Desk: <?= e($m['desk']) ?> • Assigned: <time datetime="<?= e($m['assigned_at']) ?>"><?= e($m['assigned_at']) ?></time> •
                      Status: <?= status_chip($ms) ?> • Priority: <?= e(ucfirst((string)$m['priority'])) ?>
                    </div>
                  </div>
                  <div class="flex">
                    <?= desk_link($m['desk'], (int)$m['request_id'], (string)$m['ticket_code']) ?>
                    <a class="btn outline" href="<?= e(base_url('staff/registration.php?ticket='.urlencode((string)$m['ticket_code']).'&id='.(int)$m['request_id'].'&tab='.tab_for_status($ms))) ?>">History</a>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
        <?php if($unassigned>0): ?>
          <p class="tiny" style="margin-top:8px">
            <?= badge("Unassigned: ".$unassigned, 'warn') ?> — Assign from the Registration/Repair queues.
          </p>
        <?php endif; ?>
      </section>

      <!-- Right: Charts & Latest activity -->
      <aside class="grid" aria-label="Insights">
        <section class="card">
          <h3>Request Status Distribution</h3>
          <canvas id="statusChart" height="140" role="img" aria-label="Doughnut chart of request statuses"></canvas>
          <div class="legend tiny" id="statusLegend" style="margin-top:8px"></div>
        </section>

        <section class="card">
          <h3>Daily New Requests (14 days)</h3>
          <canvas id="dailyChart" height="140" role="img" aria-label="Line chart of daily new requests"></canvas>
        </section>

        <section class="card">
          <h3>Quick Actions</h3>
          <div class="grid grid-3">
            <a class="btn primary" href="<?= e(base_url('staff/request_new.php')) ?>">New Ticket</a>
            <a class="btn outline" href="<?= e(base_url('staff/registration.php')) ?>">Intake Queue</a>
            <a class="btn outline" href="<?= e(base_url('staff/repair.php')) ?>">Repair Queue</a>
            <a class="btn outline" href="<?= e(base_url('staff/billing.php')) ?>">Billing Queue</a>
            <a class="btn outline" href="<?= e(base_url('staff/shipping.php')) ?>">Shipping Queue</a>
            <?php if(($u['role'] ?? '')==='admin'): ?>
              <a class="btn outline" href="<?= e(base_url('staff/users.php')) ?>">Manage Users</a>
            <?php endif; ?>
          </div>
          <p class="tiny" style="margin-top:8px">Tip: Press <strong>/</strong> to focus assignment search.</p>
        </section>
      </aside>
    </div>

    <section class="card" style="margin-top:14px">
      <h3>Latest Requests</h3>
      <?php if(!$latest): ?>
        <div class="empty">No recent requests.</div>
      <?php else: ?>
        <div class="tiny" style="margin-bottom:8px">Click a row to open the ticket. Headers are sortable.</div>
        <table class="table" id="latestTable" aria-describedby="latestCaption">
          <caption id="latestCaption" class="tiny" style="text-align:left; padding:6px 0">Most recent 12 tickets</caption>
          <thead>
            <tr>
              <th data-key="ticket_code" tabindex="0" role="button" aria-sort="none" aria-label="Sort by Ticket">Ticket</th>
              <th data-key="device" tabindex="0" role="button" aria-sort="none" aria-label="Sort by Device">Device</th>
              <th data-key="status" tabindex="0" role="button" aria-sort="none" aria-label="Sort by Status">Status</th>
              <th data-key="created_at" tabindex="0" role="button" aria-sort="none" aria-label="Sort by Created">Created</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach($latest as $r): ?>
  <?php
    $device = trim($r['device_type'].' '.($r['brand']? '· '.$r['brand']:'').' '.($r['model']? '· '.$r['model']:''));
    $status = normalize_status($r['status']);
    $rowUrl = row_url((int)$r['id'], (string)$r['ticket_code'], $status);
  ?>
  <tr class="row-link"
      data-href="<?= e($rowUrl) ?>"
      data-ticket_code="<?= e(strtolower((string)$r['ticket_code'])) ?>"
      data-device="<?= e(strtolower($device)) ?>"
      data-status="<?= e(strtolower($status)) ?>"
      data-created_at="<?= e($r['created_at']) ?>">
    <td><?= e($r['ticket_code']) ?></td>
    <td><?= e($device) ?></td>
    <td><?= status_chip($status) ?></td>
    <td class="tiny"><?= e($r['created_at']) ?></td>
  </tr>
<?php endforeach; ?>

          </tbody>
        </table>
      <?php endif; ?>
    </section>
  </main>

  <script src="../../assets/js/app.js" defer></script>
  <script>
    // ===== Clickable rows & keyboard access =====
    document.addEventListener('click', (e)=>{
      const tr = e.target.closest('.row-link');
      if (tr) window.location = tr.dataset.href;
    });
    document.addEventListener('keydown', (e)=>{
      const tr = e.target.closest('.row-link');
      if (tr && (e.key === 'Enter' || e.key === ' ')) {
        e.preventDefault();
        window.location = tr.dataset.href;
      }
    });

    // ===== Sortable table (client-side, accessible) =====
    (function(){
      const table = document.getElementById('latestTable'); if(!table) return;
      const ths = Array.from(table.querySelectorAll('th'));
      const tbody = table.querySelector('tbody');
      let sortKey = null, sortDir = 'asc';

      const getVal = (tr, key)=> (tr.dataset[key] || '').toString();

      function applySort(key){
        const rows = Array.from(tbody.querySelectorAll('tr'));
        const dir = (sortKey===key && sortDir==='asc') ? 'desc' : 'asc';
        rows.sort((a,b)=>{
          const av = getVal(a,key), bv = getVal(b,key);
          if (key==='created_at') {
            return dir==='asc' ? (new Date(av)-new Date(bv)) : (new Date(bv)-new Date(av));
          }
          return dir==='asc' ? av.localeCompare(bv) : bv.localeCompare(av);
        });
        const frag = document.createDocumentFragment();
        rows.forEach(r=>frag.appendChild(r));
        tbody.appendChild(frag);

        // update headers ARIA
        ths.forEach(th=>th.setAttribute('aria-sort','none'));
        const active = ths.find(t=>t.dataset.key===key);
        if (active) active.setAttribute('aria-sort', dir==='asc'?'ascending':'descending');
        sortKey = key; sortDir = dir;
      }

      ths.forEach(th=>{
        th.addEventListener('click', ()=>applySort(th.dataset.key));
        th.addEventListener('keydown', (e)=>{ if(e.key==='Enter' || e.key===' ') { e.preventDefault(); applySort(th.dataset.key); } });
      });
    })();

    // ===== Live filters for "My Assignments" + persistence =====
    (function(){
      const q = document.getElementById('as-search');
      const fDesk = document.getElementById('as-desk');
      const fStatus = document.getElementById('as-status');
      const items = Array.from(document.querySelectorAll('.as-item'));
      const KEY = 'nf_assign_filters_v1';

      function apply(){
        const text = (q.value || '').toLowerCase();
        const desk = (fDesk.value || '').toLowerCase();
        const status = (fStatus.value || '').toLowerCase();

        for (const el of items) {
          const t = el.dataset.text || '';
          const d = el.dataset.desk || '';
          const s = el.dataset.status || '';
          const visible = (!text || t.includes(text)) && (!desk || d === desk) && (!status || s === status);
          el.style.display = visible ? '' : 'none';
        }
        localStorage.setItem(KEY, JSON.stringify({text, desk, status}));
      }

      // restore filters
      try {
        const saved = JSON.parse(localStorage.getItem(KEY) || '{}');
        if (saved.text) q.value = saved.text;
        if (saved.desk) fDesk.value = saved.desk[0].toUpperCase()+saved.desk.slice(1);
        if (saved.status) {
          const opt = Array.from(fStatus.options).find(o=>o.value.toLowerCase()===saved.status);
          if (opt) fStatus.value = opt.value;
        }
      } catch(e){}
      q.addEventListener('input', apply);
      fDesk.addEventListener('change', apply);
      fStatus.addEventListener('change', apply);

      // UX: press "/" to focus search
      window.addEventListener('keydown', (e)=>{
        if (e.key === '/' && document.activeElement.tagName !== 'INPUT' && !e.metaKey && !e.ctrlKey) {
          e.preventDefault(); q.focus();
        }
      });

      apply();
    })();

    // ===== Density toggle (compact mode) =====
    (function(){
      const body = document.body;
      const cb = document.getElementById('density');
      const KEY = 'nf_density_dense';
      const saved = localStorage.getItem(KEY)==='1';
      if (saved) { body.classList.add('dense'); if (cb) cb.checked = true; }
      cb?.addEventListener('change', ()=>{
        body.classList.toggle('dense', cb.checked);
        localStorage.setItem(KEY, cb.checked ? '1' : '0');
      });
    })();

    // ===== Charts (load after Chart.js) =====
    window.addEventListener('load', ()=>{
      if (!window.Chart) return;

      // Status Doughnut
      const statusData = <?= json_encode($statusDist, JSON_UNESCAPED_SLASHES) ?>;
      const sLabels = statusData.map(d=>d.label);
      const sValues = statusData.map(d=>d.value);
      const ctx1 = document.getElementById('statusChart')?.getContext('2d');
      const statusChart = ctx1 ? new Chart(ctx1, {
        type: 'doughnut',
        data: { labels: sLabels, datasets: [{ data: sValues }] },
        options: { responsive: true, plugins: { legend: { display: false } }, cutout: '60%' }
      }) : null;

      // Legend
      const legend = document.getElementById('statusLegend');
      if (statusChart && legend) {
        const colors = statusChart.data.datasets[0].backgroundColor || [];
        (colors.length ? colors : sLabels.map(()=> '#cbd5e1')).forEach((c, i)=>{
          const el = document.createElement('div');
          el.innerHTML = '<span class="dot" style="background:'+c+'"></span> '+sLabels[i]+' ('+sValues[i]+')';
          legend.appendChild(el);
        });
      }

      // Daily Line
      const dailyLabels = <?= json_encode($labels, JSON_UNESCAPED_SLASHES) ?>;
      const dailyValues = <?= json_encode(array_values($dailyNew), JSON_UNESCAPED_SLASHES) ?>;
      const ctx2 = document.getElementById('dailyChart')?.getContext('2d');
      if (ctx2) new Chart(ctx2, {
        type: 'line',
        data: { labels: dailyLabels, datasets: [{ label: 'New Requests', data: dailyValues, tension: 0.3, fill: false }] },
        options: {
          responsive: true,
          plugins: { legend: { display: false }, tooltip: { mode: 'index', intersect: false } },
          scales: { x: { ticks: { maxRotation: 0, autoSkip: true } }, y: { beginAtZero: true, precision: 0 } }
        }
      });
    });

    // ===== Localize <time> elements to staff’s locale =====
    (function(){
      const fmt = new Intl.DateTimeFormat(undefined, { year:'numeric', month:'short', day:'2-digit', hour:'2-digit', minute:'2-digit' });
      document.querySelectorAll('time.local-time').forEach(t=>{
        const iso = t.getAttribute('datetime'); if (!iso) return;
        const d = new Date(iso.replace(' ','T')); // MySQL DATETIME to ISO
        if (!isNaN(d)) t.textContent = fmt.format(d);
      });
    })();
  </script>
</body>
</html>
