<?php
// /htdocs/it_repair/public/staff/admin_dashboard.php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';
require_role('admin');

$u = $_SESSION['user'] ?? ['name' => 'Admin'];

/**
 * Notes for DB maintainers (run once, outside PHP):
 * CREATE INDEX IF NOT EXISTS idx_repair_requests_status ON repair_requests(status);
 * CREATE INDEX IF NOT EXISTS idx_repair_requests_created_at ON repair_requests(created_at);
 * CREATE INDEX IF NOT EXISTS idx_rsh_request_id ON request_status_history(request_id);
 * CREATE INDEX IF NOT EXISTS idx_users_created_at ON users(created_at);
 */

function cache_get(string $key) {
    if (function_exists('apcu_fetch')) {
        $ok = false;
        $val = apcu_fetch($key, $ok);
        if ($ok) return $val;
    }
    return null;
}
function cache_set(string $key, $val, int $ttl = 30): void {
    if (function_exists('apcu_store')) {
        @apcu_store($key, $val, $ttl);
    }
}
function safe_query(callable $cb, $fallback = []) {
    try {
        return $cb();
    } catch (Throwable $e) {
        // Optional: log_error($e);
        return $fallback;
    }
}

$db = db();

/** --------- Stats (cached) --------- */
$stats = cache_get('admin_stats');
if ($stats === null) {
    $stats = safe_query(function () use ($db) {
        return [
            'total_users'       => (int)$db->query("SELECT COUNT(*) FROM users")->fetchColumn(),
            'total_requests'    => (int)$db->query("SELECT COUNT(*) FROM repair_requests")->fetchColumn(),
            'active_repairs'    => (int)$db->query("SELECT COUNT(*) FROM repair_requests WHERE status = 'In Repair'")->fetchColumn(),
            'pending_approval'  => (int)$db->query("SELECT COUNT(*) FROM repair_requests WHERE status = 'Awaiting Quote Approval'")->fetchColumn(),
        ];
    }, ['total_users'=>0,'total_requests'=>0,'active_repairs'=>0,'pending_approval'=>0]);
    cache_set('admin_stats', $stats, 20);
}

/** --------- Recent activity --------- */
$recent_activity = safe_query(function () use ($db) {
    $stmt = $db->prepare("
        SELECT rsh.status, rsh.note, rsh.created_at, u.name AS actor_name, rr.ticket_code
        FROM request_status_history rsh
        LEFT JOIN users u ON u.id = rsh.changed_by
        JOIN repair_requests rr ON rr.id = rsh.request_id
        ORDER BY rsh.id DESC
        LIMIT 10
    ");
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}, []);

/** --------- Recent users --------- */
$recent_users = safe_query(function () use ($db) {
    $stmt = $db->prepare("
        SELECT id, name, email, role, created_at
        FROM users
        ORDER BY id DESC
        LIMIT 5
    ");
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}, []);

/** --------- Status distribution --------- */
$status_distribution = cache_get('admin_status_distribution');
if ($status_distribution === null) {
    $status_distribution = safe_query(function () use ($db) {
        $rows = $db->query("
            SELECT status, COUNT(*) AS count
            FROM repair_requests
            GROUP BY status
        ")->fetchAll(PDO::FETCH_ASSOC);
        $out = [];
        foreach ($rows as $r) {
            $out[(string)$r['status']] = (int)$r['count'];
        }
        return $out;
    }, []);
    cache_set('admin_status_distribution', $status_distribution, 30);
}

/** --------- Daily new requests (last 14 full days, zero-filled) --------- */
$daily_requests = cache_get('admin_daily_requests_14');
if ($daily_requests === null) {
    $daily_rows = safe_query(function () use ($db) {
        $stmt = $db->prepare("
            SELECT DATE(created_at) AS date, COUNT(*) AS count
            FROM repair_requests
            WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 13 DAY)
            GROUP BY DATE(created_at)
            ORDER BY date ASC
        ");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }, []);

    // Build a continuous series for 14 days including today
    $map = [];
    foreach ($daily_rows as $r) {
        $map[$r['date']] = (int)$r['count'];
    }
    $series = [];
    $start = new DateTime('today -13 days');
    for ($i = 0; $i < 14; $i++) {
        $d = clone $start;
        $d->modify("+$i day");
        $key = $d->format('Y-m-d');
        $series[] = ['date' => $key, 'count' => $map[$key] ?? 0];
    }
    $daily_requests = $series;
    cache_set('admin_daily_requests_14', $daily_requests, 30);
}

/** --------- Status color tokens (CSS var fallbacks handled in CSS) --------- */
$statusColors = [
    'Received'                => 'var(--status-received)',
    'In Repair'               => 'var(--status-inrepair)',
    'Awaiting Quote Approval' => 'var(--status-awaiting)',
    'Billed'                  => 'var(--status-billed)',
    'Shipped'                 => 'var(--status-shipped)',
    'Delivered'               => 'var(--status-delivered)',
    'Rejected'                => 'var(--status-rejected)',
];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta http-equiv="x-ua-compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Admin Dashboard — NexusFix</title>

    <!-- Suggest setting a strong CSP in a global bootstrap/header to avoid inline scripts/styles where possible -->
    <!-- Example (set via header() in PHP, not <meta>): 
         Content-Security-Policy: default-src 'self'; script-src 'self' https://cdn.jsdelivr.net; style-src 'self'; img-src 'self' data:; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self' -->

    <link rel="stylesheet" href="<?= e(base_url('assets/css/styles.css')) ?>" />

    <!-- Chart.js with local fallback (host your own copy at /assets/vendor/chart.4.4.0.min.js) -->
    <script defer src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <script>
      // Local fallback if CDN blocked
      window.addEventListener('error', function(e){
        if (e.target && e.target.tagName === 'SCRIPT' && /chart\.umd\.min\.js/.test(e.target.src)) {
          var s = document.createElement('script');
          s.defer = true;
          s.src = "<?= e(base_url('assets/vendor/chart.4.4.0.min.js')) ?>";
          document.head.appendChild(s);
        }
      }, true);
    </script>

    <style>
        :root {
            /* Core palette */
            --bg: #0b0f14;
            --card: #11161d;
            --text: #e7edf5;
            --muted: #a0aec0;
            --border: #1e2630;
            --accent: #3d8bff;

            /* Status colors (AA contrast on dark) */
            --status-received: #3d8bff;
            --status-inrepair: #f59e0b;
            --status-awaiting: #fbbf24;
            --status-billed: #8b5cf6;
            --status-shipped: #10b981;
            --status-delivered: #14b8a6;
            --status-rejected: #ef4444;

            /* Cards */
            --radius: 16px;
            --shadow: 0 8px 24px rgba(0,0,0,.25);
        }
        @media (prefers-color-scheme: light) {
            :root {
                --bg: #f7fafc;
                --card: #fff;
                --text: #0f1720;
                --muted: #4a5568;
                --border: #e2e8f0;
                --shadow: 0 8px 24px rgba(0,0,0,.08);
            }
        }
        @media (prefers-reduced-motion: reduce) {
            * { animation: none !important; transition: none !important; }
        }

        html, body { background: var(--bg); color: var(--text); }
        a:focus, button:focus, [tabindex]:focus { outline: 2px solid var(--accent); outline-offset: 2px; }

        .container { min-height:60vh; padding: 20px; max-width: 1400px; margin: 0 auto; }
        .hero { margin-bottom: 30px; }
        .subtitle { color: var(--muted); }

        .grid { display: grid; gap: 20px; }
        .stat-cards { grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); margin-bottom: 30px; }

        .card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            padding: 20px;
        }

        .stat-card { transition: transform .15s ease; }
        .stat-card:hover { transform: translateY(-3px); }
        .stat-card h3 { font-size: .9rem; font-weight: 600; color: var(--muted); margin-bottom: 10px; }
        .stat-card .value { font-size: 2rem; font-weight: 800; color: var(--text); line-height: 1; }

        .columns { grid-template-columns: 2fr 1fr; }
        @media (max-width: 980px) { .columns { grid-template-columns: 1fr; } }

        .activity-item {
            display: grid; grid-template-columns: 40px 1fr auto; gap: 15px;
            padding: 14px 0; border-bottom: 1px solid var(--border);
        }
        .activity-item:last-child { border-bottom: none; }

        .status-badge {
            display: inline-block; padding: 4px 10px; border-radius: 999px;
            font-size: .75rem; font-weight: 700; color: #0b0f14; /* readable on bright status colors */
            background: var(--accent);
        }

        .user-card { display: grid; grid-template-columns: 40px 1fr auto; align-items: center; gap: 15px; padding: 12px 0; border-bottom: 1px solid var(--border); }
        .user-card:last-child { border-bottom: none; }
        .user-avatar {
            width: 40px; height: 40px; border-radius: 50%;
            background: var(--accent); color: white; display: grid; place-items: center;
            font-weight: 900; text-transform: uppercase;
        }

        .chart-card { margin-top: 20px; }
        .muted { color: var(--muted); font-size: .9rem; }

        .empty {
            border: 1px dashed var(--border); border-radius: var(--radius);
            padding: 16px; color: var(--muted);
        }

        /* Progress skeletons */
        .skeleton {
            position: relative; overflow: hidden; background: linear-gradient(90deg, rgba(255,255,255,0.06), rgba(255,255,255,0.12), rgba(255,255,255,0.06));
            border-radius: 8px; height: 12px;
            animation: shimmer 1.2s infinite;
        }
        @keyframes shimmer {
            0% { background-position: -200px 0; }
            100% { background-position: 200px 0; }
        }
    </style>
</head>
<body>
    <?php require __DIR__ . '/../../includes/header.php'; ?>

    <main class="container" role="main" aria-labelledby="dashboardTitle">
        <div class="hero">
            <h2 id="dashboardTitle" style="margin-bottom: 5px;">Admin Dashboard</h2>
            <p class="subtitle">Welcome back, <?= e($u['name']) ?></p>
        </div>

        <!-- Stats -->
        <section aria-label="Statistics" class="grid stat-cards">
            <article class="card stat-card" aria-live="polite"><h3>Total Users</h3><div class="value"><?= (int)$stats['total_users'] ?></div><div class="muted">Registered in system</div></article>
            <article class="card stat-card"><h3>Repair Requests</h3><div class="value"><?= (int)$stats['total_requests'] ?></div><div class="muted">All-time requests</div></article>
            <article class="card stat-card"><h3>Active Repairs</h3><div class="value"><?= (int)$stats['active_repairs'] ?></div><div class="muted">Currently in progress</div></article>
            <article class="card stat-card"><h3>Pending Approval</h3><div class="value"><?= (int)$stats['pending_approval'] ?></div><div class="muted">Awaiting customer response</div></article>
        </section>

        <!-- Two columns -->
        <section class="grid columns" aria-label="Recent activity and users">
            <article class="card" aria-labelledby="recentActivityTitle">
                <h3 id="recentActivityTitle" style="margin-bottom: 12px;">Recent Activity</h3>
                <?php if (!$recent_activity): ?>
                    <div class="empty" role="status">No recent activity yet.</div>
                <?php else: ?>
                    <div>
                        <?php foreach ($recent_activity as $activity): ?>
                            <?php
                                $status = (string)($activity['status'] ?? 'Updated');
                                $colorVar = $statusColors[$status] ?? 'var(--accent)';
                                $ticket = (string)($activity['ticket_code'] ?? '—');
                                $note = (string)($activity['note'] ?? 'Status update');
                                $when = (string)($activity['created_at'] ?? '');
                                $actor = isset($activity['actor_name']) && $activity['actor_name'] !== null ? (string)$activity['actor_name'] : '';
                            ?>
                            <div class="activity-item">
                                <div>
                                    <span class="status-badge" style="background: <?= e($colorVar) ?>"><?= e($status) ?></span>
                                </div>
                                <div>
                                    <strong>Ticket <?= e($ticket) ?></strong>
                                    <div class="muted"><?= e($note) ?></div>
                                </div>
                                <div class="muted" style="text-align:right;">
                                    <span class="js-datetime" data-dt="<?= e($when) ?>"><?= e($when) ?></span>
                                    <?php if ($actor !== ''): ?>
                                        <div>by <?= e($actor) ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </article>

            <aside class="card" aria-labelledby="recentUsersTitle">
                <h3 id="recentUsersTitle" style="margin-bottom: 12px;">Recent Users</h3>
                <?php if (!$recent_users): ?>
                    <div class="empty">No users yet.</div>
                <?php else: ?>
                    <div>
                        <?php foreach ($recent_users as $user): ?>
                            <?php
                                $uname = (string)($user['name'] ?? '—');
                                $initial = strtoupper(mb_substr($uname, 0, 1, 'UTF-8'));
                                $uemail = (string)($user['email'] ?? '');
                                $urole = (string)($user['role'] ?? 'user');
                            ?>
                            <div class="user-card">
                                <div class="user-avatar" aria-hidden="true"><?= e($initial) ?></div>
                                <div>
                                    <strong><?= e($uname) ?></strong>
                                    <div class="muted"><?= e($uemail) ?></div>
                                </div>
                                <div><span class="status-badge" style="background: var(--card); color: var(--text); border: 1px solid var(--border);"><?= e($urole) ?></span></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </aside>
        </section>

        <!-- Charts -->
        <section class="chart-card card" aria-labelledby="statusChartTitle">
            <h3 id="statusChartTitle">Request Status Distribution</h3>
            <div id="statusChartSkeleton" class="skeleton" style="height: 140px; margin-top: 12px;"></div>
            <canvas id="statusChart" height="120" role="img" aria-label="Distribution of requests by status" style="display:none;"></canvas>
            <?php if (!$status_distribution): ?>
                <div class="empty" id="statusEmpty">No requests yet.</div>
            <?php endif; ?>
        </section>

        <section class="chart-card card" aria-labelledby="dailyChartTitle">
            <h3 id="dailyChartTitle">Daily New Requests (Last 14 Days)</h3>
            <div id="dailyChartSkeleton" class="skeleton" style="height: 160px; margin-top: 12px;"></div>
            <canvas id="dailyChart" height="120" role="img" aria-label="New requests per day for the last 14 days" style="display:none;"></canvas>
            <?php if (!$daily_requests): ?>
                <div class="empty" id="dailyEmpty">No data to display.</div>
            <?php endif; ?>
        </section>
    </main>

    <script src="<?= e(base_url('assets/js/app.js')) ?>"></script>
    <script>
    // Utility: format server timestamps to local, short readable form
    (function(){
        var nodes = document.querySelectorAll('.js-datetime[data-dt]');
        if (!nodes.length) return;
        try {
            var fmt = new Intl.DateTimeFormat(undefined, { year:'numeric', month:'short', day:'2-digit', hour:'2-digit', minute:'2-digit' });
            nodes.forEach(function(n){
                var raw = n.getAttribute('data-dt');
                if (!raw) return;
                var d = new Date(raw.replace(' ', 'T')); // naive; relies on server sending ISO-ish
                if (!isNaN(d)) n.textContent = fmt.format(d);
            });
        } catch(e) {}
    })();

    // Chart data from PHP
    const STATUS_DATA = <?= json_encode($status_distribution, JSON_UNESCAPED_UNICODE) ?>;
    const DAILY_DATA  = <?= json_encode($daily_requests, JSON_UNESCAPED_UNICODE) ?>;

    // Respect prefers-reduced-motion for chart animations
    const reducedMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    function show(el){ el && (el.style.display = 'block'); }
    function hide(el){ el && (el.style.display = 'none'); }

    function ready(fn){
        if (document.readyState === 'complete' || document.readyState === 'interactive') { setTimeout(fn, 0); }
        else document.addEventListener('DOMContentLoaded', fn);
    }

    ready(function initCharts(){
        // Wait for Chart to exist (CDN or local fallback)
        (function waitForChart(attempts){
            if (window.Chart && window.Chart.defaults) return buildCharts();
            if (attempts > 40) return; // abort quietly after ~2s
            setTimeout(function(){ waitForChart(attempts+1); }, 50);
        })(0);

        function buildCharts(){
            // Shared defaults themed from CSS vars (no fixed colors)
            try {
                Chart.defaults.color = getComputedStyle(document.documentElement).getPropertyValue('--text').trim() || '#e7edf5';
                Chart.defaults.borderColor = getComputedStyle(document.documentElement).getPropertyValue('--border').trim() || '#1e2630';
                Chart.defaults.animation = reducedMotion ? false : { duration: 400 };
                Chart.defaults.responsive = true;
                Chart.defaults.maintainAspectRatio = false;
                Chart.defaults.plugins.legend.labels.boxWidth = 14;
            } catch(e){}

            // ----- Status (Doughnut) -----
            (function buildStatus(){
                const wrap = document.getElementById('statusChart');
                const skel = document.getElementById('statusChartSkeleton');
                const empty = document.getElementById('statusEmpty');

                const labels = Object.keys(STATUS_DATA || {});
                const data = Object.values(STATUS_DATA || {});
                if (!labels.length || !data.length) {
                    hide(skel); show(empty);
                    return;
                }
                const statusColorVars = {
                    'Received': 'var(--status-received)',
                    'In Repair': 'var(--status-inrepair)',
                    'Awaiting Quote Approval': 'var(--status-awaiting)',
                    'Billed': 'var(--status-billed)',
                    'Shipped': 'var(--status-shipped)',
                    'Delivered': 'var(--status-delivered)',
                    'Rejected': 'var(--status-rejected)',
                };
                const bg = labels.map(l => getComputedStyle(document.documentElement).getPropertyValue(statusColorVars[l] || '--accent').trim() || '#3d8bff');

                hide(skel); hide(empty); show(wrap);
                new Chart(wrap.getContext('2d'), {
                    type: 'doughnut',
                    data: { labels, datasets: [{ data, backgroundColor: bg }] },
                    options: {
                        plugins: {
                            legend: { position: 'bottom' },
                            tooltip: { mode: 'index', intersect: false }
                        },
                        cutout: '55%'
                    }
                });
            })();

            // ----- Daily (Line) -----
            (function buildDaily(){
                const wrap = document.getElementById('dailyChart');
                const skel = document.getElementById('dailyChartSkeleton');
                const empty = document.getElementById('dailyEmpty');

                const labels = (DAILY_DATA || []).map(d => d.date);
                const data = (DAILY_DATA || []).map(d => Number(d.count || 0));
                if (!labels.length || !data.length) {
                    hide(skel); show(empty);
                    return;
                }

                hide(skel); hide(empty); show(wrap);
                new Chart(wrap.getContext('2d'), {
                    type: 'line',
                    data: {
                        labels,
                        datasets: [{
                            label: 'Requests',
                            data,
                            fill: true,
                            borderWidth: 2,
                            pointRadius: 2,
                            pointHoverRadius: 4,
                            borderColor: getComputedStyle(document.documentElement).getPropertyValue('--accent').trim() || '#3d8bff',
                            backgroundColor: 'rgba(61,139,255,0.15)',
                            tension: 0.35,
                        }]
                    },
                    options: {
                        plugins: {
                            legend: { display: false },
                            tooltip: { mode: 'index', intersect: false }
                        },
                        interaction: { mode: 'nearest', intersect: false },
                        scales: {
                            x: { grid: { display: false } },
                            y: { beginAtZero: true, ticks: { precision: 0 } }
                        }
                    }
                });
            })();
        }
    });
    </script>
</body>
</html>
