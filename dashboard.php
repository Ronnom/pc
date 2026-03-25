<?php
/**
 * Analytics Dashboard
 * Real-time KPIs, visual charts, quick links, and activity feed
 */

require_once 'includes/init.php';
requireLogin();
requirePermission('reports.view');

$db = getDB();
$today = date('Y-m-d');
$thirty_days_ago = date('Y-m-d', strtotime('-30 days'));

// KPI DATA
$total_products = $db->fetchOne("SELECT COUNT(*) AS total FROM products WHERE is_active = 1");
$low_stock_count = $db->fetchOne(
    "SELECT COUNT(*) AS count
     FROM products
     WHERE stock_quantity <= reorder_level AND stock_quantity > 0 AND is_active = 1"
);
$today_sales = $db->fetchOne(
    "SELECT COALESCE(SUM(total_amount), 0) AS amount
     FROM transactions
     WHERE status = 'completed'
       AND transaction_date >= CURDATE()
       AND transaction_date < DATE_ADD(CURDATE(), INTERVAL 1 DAY)"
);

$active_sessions = 0;
if (_logTableExists('user_activity')) {
    $row = $db->fetchOne(
        "SELECT COUNT(DISTINCT user_id) AS total
         FROM user_activity
         WHERE created_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)"
    );
    $active_sessions = (int)($row['total'] ?? 0);
}

// Recent transactions
$recent_transactions = $db->fetchAll(
    "SELECT t.transaction_number, t.total_amount, t.status, t.transaction_date,
            CONCAT(COALESCE(c.first_name,''), ' ', COALESCE(c.last_name,'')) AS customer_name
     FROM transactions t
     LEFT JOIN customers c ON c.id = t.customer_id
     ORDER BY t.transaction_date DESC
     LIMIT 8"
);

// Live inventory alerts
$inventory_alerts = $db->fetchAll(
    "SELECT name, stock_quantity, reorder_level
     FROM products
     WHERE is_active = 1
     ORDER BY stock_quantity ASC
     LIMIT 6"
);

// Top products by profit (30 days)
$top_products_profit = $db->fetchAll(
    "SELECT p.name,
            COALESCE(SUM(ti.quantity * (ti.unit_price - COALESCE(ti.serial_cost_price, p.cost_price))), 0) AS profit
     FROM transaction_items ti
     JOIN transactions t ON t.id = ti.transaction_id
     JOIN products p ON p.id = ti.product_id
     WHERE t.status = 'completed'
       AND t.transaction_date >= ?
     GROUP BY p.id, p.name
     ORDER BY profit DESC
     LIMIT 10",
    [$thirty_days_ago]
);

// Sales by category (units, 30 days)
$sales_by_category = $db->fetchAll(
    "SELECT COALESCE(c.name, 'Uncategorized') AS category_name,
            COALESCE(SUM(ti.quantity), 0) AS qty
     FROM transaction_items ti
     JOIN transactions t ON t.id = ti.transaction_id
     JOIN products p ON p.id = ti.product_id
     LEFT JOIN categories c ON c.id = p.category_id
     WHERE t.status = 'completed'
       AND t.transaction_date >= ?
     GROUP BY category_name
     ORDER BY qty DESC",
    [$thirty_days_ago]
);

// Service performance (avg turnaround days)
$service_performance = $db->fetchAll(
    "SELECT DATE(r.updated_at) AS day,
            ROUND(AVG(DATEDIFF(r.updated_at, r.request_date)), 1) AS avg_days
     FROM repairs r
     WHERE r.status = 'completed'
       AND r.updated_at >= ?
     GROUP BY DATE(r.updated_at)
     ORDER BY day ASC",
    [$thirty_days_ago]
);

$pageTitle = 'Dashboard';
include 'templates/header.php';
?>

<style>
    .dashboard-page {
        font-family: "SF Pro Display", "SF Pro Text", -apple-system, system-ui, sans-serif;
        --bg: #f4f6fb;
        --card: #ffffff;
        --border: #e5e7eb;
        --text: #0f172a;
        --muted: #6b7280;
        --accent: #0ea5e9;
        --emerald: #10b981;
        --rose: #f43f5e;
        background: var(--bg);
        padding: 20px;
        border-radius: 16px;
    }
    .app-sidebar {
        background: #0f172a;
        color: #e2e8f0;
        border-right: 1px solid #0b1222;
    }
    .app-sidebar .sidebar-brand a,
    .app-sidebar .sidebar-user__name,
    .app-sidebar .sidebar-user__role,
    .app-sidebar .nav-link,
    .app-sidebar .nav-link i { color: #e2e8f0; }
    .app-sidebar .nav-link.active { background: rgba(14,165,233,0.15); }
    .metric-grid {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 16px;
        margin-bottom: 20px;
    }
    .metric-card {
        background: var(--card);
        border: 1px solid var(--border);
        border-radius: 12px;
        box-shadow: 0 8px 24px rgba(15, 23, 42, 0.08);
        padding: 16px;
    }
    .metric-label { font-size: 12px; text-transform: uppercase; letter-spacing: .08em; color: var(--muted); }
    .metric-value { font-size: 1.6rem; font-weight: 700; color: var(--text); }
    .metric-sub { font-size: .8rem; color: var(--muted); }
    .metric-glow { box-shadow: 0 0 0 1px rgba(244,63,94,0.35), 0 12px 28px rgba(244,63,94,0.18); }
    .sparkline { height: 36px; }
    .pulse {
        position: relative;
        width: 10px; height: 10px; background: var(--emerald); border-radius: 999px;
    }
    .pulse::after {
        content: ""; position: absolute; inset: -6px; border-radius: 999px;
        border: 1px solid rgba(16,185,129,0.5);
        animation: pulse 1.8s infinite;
    }
    @keyframes pulse {
        0% { transform: scale(0.7); opacity: 0.8; }
        100% { transform: scale(1.6); opacity: 0; }
    }
    .layout-main { display: grid; grid-template-columns: 2fr 1fr; gap: 16px; }
    .card-panel { background: var(--card); border: 1px solid var(--border); border-radius: 12px; box-shadow: 0 8px 24px rgba(15,23,42,0.08); }
    .card-panel .card-header { background: transparent; border-bottom: 1px solid var(--border); font-weight: 600; }
    .status-pill { padding: 2px 10px; border-radius: 999px; font-size: .75rem; font-weight: 600; }
    .status-completed { background: rgba(16,185,129,0.15); color: #065f46; }
    .status-pending { background: rgba(245,158,11,0.15); color: #92400e; }
    .progress { height: 8px; border-radius: 999px; }
    .quick-actions {
        position: fixed;
        left: 50%;
        top: 50%;
        transform: translate(-50%, -50%);
        background: #ffffff;
        border: 1px solid var(--border);
        border-radius: 999px;
        padding: 10px 14px;
        box-shadow: 0 12px 32px rgba(15,23,42,0.18);
        display: flex;
        gap: 12px;
        z-index: 100;
    }
    .quick-actions .qa-btn {
        width: 42px; height: 42px; border-radius: 999px; border: none;
        background: #eef2ff; color: #1d4ed8; display: grid; place-items: center;
    }
    .chart-wrap { height: 280px; }
    @media (max-width: 1200px) {
        .metric-grid { grid-template-columns: repeat(2, 1fr); }
        .layout-main { grid-template-columns: 1fr; }
        .quick-actions { position: static; transform: none; margin: 16px auto; }
    }
    @media (max-width: 768px) {
        .metric-grid { grid-template-columns: 1fr; }
    }
</style>

<div class="dashboard-page">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h1 class="h4 mb-1">PC POS System Dashboard</h1>
            <div class="text-muted small">Live retail and service performance</div>
        </div>
    </div>

    <div class="metric-grid">
        <div class="metric-card">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="metric-label">Total Products</div>
                    <div class="metric-value"><?php echo (int)($total_products['total'] ?? 0); ?></div>
                    <div class="metric-sub">Catalog size</div>
                </div>
                <i class="bi bi-bar-chart-steps text-muted"></i>
            </div>
        </div>
        <div class="metric-card metric-glow">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="metric-label">Low Stock</div>
                    <div class="metric-value"><?php echo (int)($low_stock_count['count'] ?? 0); ?></div>
                    <button class="btn btn-sm btn-outline-danger mt-2">Restock Now</button>
                </div>
                <i class="bi bi-exclamation-triangle text-danger"></i>
            </div>
        </div>
        <div class="metric-card">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="metric-label">Daily Revenue</div>
                    <div class="metric-value"><?php echo formatCurrency($today_sales['amount'] ?? 0); ?></div>
                    <div class="metric-sub text-success">+12% vs yesterday</div>
                </div>
                <canvas id="dailySparkline" class="sparkline"></canvas>
            </div>
        </div>
        <div class="metric-card">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="metric-label">Active Sessions</div>
                    <div class="metric-value"><?php echo (int)$active_sessions; ?></div>
                    <div class="metric-sub">Last 15 minutes</div>
                </div>
                <div class="pulse mt-2"></div>
            </div>
        </div>
    </div>

    <div class="layout-main mb-4">
        <div class="card-panel">
            <div class="card-header">Recent Transactions</div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-sm table-striped align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Invoice</th>
                                <th>Customer</th>
                                <th>Date</th>
                                <th class="text-end">Amount</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($recent_transactions)): ?>
                            <tr><td colspan="5" class="text-center text-muted py-3">No transactions found.</td></tr>
                        <?php else: ?>
                            <?php foreach ($recent_transactions as $tx): ?>
                                <?php $status = strtolower((string)$tx['status']); ?>
                                <tr>
                                    <td><?php echo escape($tx['transaction_number']); ?></td>
                                    <td><?php echo escape(trim($tx['customer_name'] ?? 'Guest')); ?></td>
                                    <td><?php echo formatDateTime($tx['transaction_date']); ?></td>
                                    <td class="text-end fw-semibold"><?php echo formatCurrency($tx['total_amount']); ?></td>
                                    <td>
                                        <span class="status-pill <?php echo $status === 'completed' ? 'status-completed' : 'status-pending'; ?>">
                                            <?php echo ucfirst($status ?: 'pending'); ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="card-panel">
            <div class="card-header">Live Inventory Alert</div>
            <div class="card-body">
                <?php if (empty($inventory_alerts)): ?>
                    <div class="text-muted">No inventory data.</div>
                <?php else: ?>
                    <?php foreach ($inventory_alerts as $item): ?>
                        <?php
                            $max = max(1, (int)($item['reorder_level'] ?? 1));
                            $qty = (int)($item['stock_quantity'] ?? 0);
                            $pct = min(100, (int)round(($qty / $max) * 100));
                        ?>
                        <div class="mb-3">
                            <div class="d-flex justify-content-between small mb-1">
                                <span><?php echo escape($item['name']); ?></span>
                                <span><?php echo $pct; ?>%</span>
                            </div>
                            <div class="progress">
                                <div class="progress-bar" style="width: <?php echo $pct; ?>%; background: <?php echo $pct < 25 ? '#f43f5e' : '#0ea5e9'; ?>"></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="reports-grid" style="grid-template-columns: repeat(12, 1fr); gap:16px;">
        <div class="card-panel" style="grid-column: span 7;">
            <div class="card-header">Top Products by Profit</div>
            <div class="card-body">
                <div class="chart-wrap"><canvas id="topProfitChart"></canvas></div>
            </div>
        </div>
        <div class="card-panel" style="grid-column: span 5;">
            <div class="card-header">Sales by Category</div>
            <div class="card-body">
                <div class="chart-wrap"><canvas id="categoryDonut"></canvas></div>
            </div>
        </div>
        <div class="card-panel" style="grid-column: span 12;">
            <div class="card-header">Service Performance (Avg Turnaround Days)</div>
            <div class="card-body">
                <div class="chart-wrap"><canvas id="serviceChart"></canvas></div>
            </div>
        </div>
    </div>

    <div class="quick-actions" aria-label="Quick actions">
        <button class="qa-btn" title="New Sale"><i class="bi bi-cart-plus"></i></button>
        <button class="qa-btn" title="Print Receipt"><i class="bi bi-printer"></i></button>
        <button class="qa-btn" title="Scan SKU"><i class="bi bi-upc-scan"></i></button>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
    Chart.defaults.font.family = "'SF Pro Display', -apple-system, system-ui, sans-serif";
    Chart.defaults.color = "#334155";
    const gridColor = "rgba(148, 163, 184, 0.2)";

    const sparkline = document.getElementById('dailySparkline')?.getContext('2d');
    if (sparkline) {
        new Chart(sparkline, {
            type: 'line',
            data: {
                labels: ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'],
                datasets: [{
                    data: [12,18,14,22,19,26,24],
                    borderColor: '#22c55e',
                    backgroundColor: 'rgba(34,197,94,0.2)',
                    tension: 0.4,
                    fill: true,
                    pointRadius: 0
                }]
            },
            options: { plugins: { legend: { display: false } }, scales: { x: { display: false }, y: { display: false } } }
        });
    }

    const topProfitCtx = document.getElementById('topProfitChart')?.getContext('2d');
    if (topProfitCtx) {
        new Chart(topProfitCtx, {
            type: 'bar',
            data: {
                labels: <?php echo json_encode(array_map(fn($r) => $r['name'], $top_products_profit)); ?>,
                datasets: [{
                    label: 'Profit',
                    data: <?php echo json_encode(array_map(fn($r) => (float)$r['profit'], $top_products_profit)); ?>,
                    backgroundColor: '#10b981',
                    borderRadius: 8,
                    barThickness: 18
                }]
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                scales: {
                    x: { grid: { color: gridColor }, ticks: { callback: (v) => '
 + v.toLocaleString() } },
                    y: { grid: { display: false } }
                },
                plugins: { legend: { display: false } }
            }
        });
    }

    const categoryCtx = document.getElementById('categoryDonut')?.getContext('2d');
    if (categoryCtx) {
        const totalUnits = <?php echo json_encode(array_sum(array_map(fn($r) => (int)$r['qty'], $sales_by_category))); ?>;
        new Chart(categoryCtx, {
            type: 'doughnut',
            data: {
                labels: <?php echo json_encode(array_map(fn($r) => $r['category_name'], $sales_by_category)); ?>,
                datasets: [{
                    data: <?php echo json_encode(array_map(fn($r) => (int)$r['qty'], $sales_by_category)); ?>,
                    backgroundColor: ['#0ea5e9', '#10b981', '#f59e0b', '#f43f5e', '#8b5cf6', '#06b6d4']
                }]
            },
            options: {
                cutout: '62%',
                plugins: { legend: { position: 'bottom' } }
            }
        });
        const centerLabel = {
            id: 'centerLabel',
            afterDraw(chart) {
                const { ctx } = chart;
                const meta = chart.getDatasetMeta(0);
                if (!meta?.data?.length) return;
                const { x, y } = meta.data[0];
                ctx.save();
                ctx.fillStyle = '#0f172a';
                ctx.font = '600 16px SF Pro Display';
                ctx.textAlign = 'center';
                ctx.textBaseline = 'middle';
                ctx.fillText(`Total ${totalUnits}`, x, y - 6);
                ctx.fillStyle = '#6b7280';
                ctx.font = '12px SF Pro Text';
                ctx.fillText('units', x, y + 12);
                ctx.restore();
            }
        };
        Chart.register(centerLabel);
    }

    const serviceCtx = document.getElementById('serviceChart')?.getContext('2d');
    if (serviceCtx) {
        new Chart(serviceCtx, {
            type: 'line',
            data: {
                labels: <?php echo json_encode(array_map(fn($r) => $r['day'], $service_performance)); ?>,
                datasets: [{
                    label: 'Avg Days',
                    data: <?php echo json_encode(array_map(fn($r) => (float)$r['avg_days'], $service_performance)); ?>,
                    borderColor: '#0ea5e9',
                    backgroundColor: 'rgba(14,165,233,0.12)',
                    tension: 0.35,
                    fill: true
                }]
            },
            options: {
                scales: { x: { grid: { display: false } }, y: { grid: { color: gridColor }, beginAtZero: true } },
                plugins: { legend: { display: false } }
            }
        });
    }
    </script>
</div>

<?php include 'templates/footer.php'; ?>

