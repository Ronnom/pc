<?php
/**
 * Sales Analytics Page
 * Advanced analytics: category/brand breakdown, trends, peak hours, period comparisons
 */

require_once 'includes/init.php';
requireLogin();
requirePermission('sales.view');

$db = getDB();

// Get date range parameters
$date_from = trim($_GET['from'] ?? date('Y-m-01'));
$date_to = trim($_GET['to'] ?? date('Y-m-d'));
$compare_from = trim($_GET['compare_from'] ?? '');
$compare_to = trim($_GET['compare_to'] ?? '');
$period = trim($_GET['period'] ?? 'daily'); // daily, weekly, monthly

// Validate dates
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) $date_from = date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to)) $date_to = date('Y-m-d');

// Sales by category
$by_category = $db->fetchAll(
    "SELECT c.id, c.name, 
            COUNT(DISTINCT t.id) as transactions,
            SUM(ti.quantity) as total_qty,
            SUM(ti.total) as total_revenue
     FROM transaction_items ti
     JOIN products p ON ti.product_id = p.id
     JOIN categories c ON p.category_id = c.id
     JOIN transactions t ON ti.transaction_id = t.id
     WHERE t.status = 'completed' AND DATE(t.transaction_date) BETWEEN ? AND ?
     GROUP BY c.id
     ORDER BY total_revenue DESC",
    [$date_from, $date_to]
);

// Sales by brand
$by_brand = $db->fetchAll(
    "SELECT p.brand,
            COUNT(DISTINCT t.id) as transactions,
            SUM(ti.quantity) as total_qty,
            SUM(ti.total) as total_revenue
     FROM transaction_items ti
     JOIN products p ON ti.product_id = p.id
     JOIN transactions t ON ti.transaction_id = t.id
     WHERE t.status = 'completed' AND DATE(t.transaction_date) BETWEEN ? AND ? AND p.brand IS NOT NULL
     GROUP BY p.brand
     ORDER BY total_revenue DESC
     LIMIT 15",
    [$date_from, $date_to]
);

// Sales trend (daily by default)
$trend_groupby = match($period) {
    'weekly' => 'WEEK(t.transaction_date)',
    'monthly' => 'MONTH(t.transaction_date)',
    default => 'DATE(t.transaction_date)'
};

$trends = $db->fetchAll(
    "SELECT DATE(t.transaction_date) as date,
            COUNT(t.id) as transactions,
            SUM(t.total_amount) as total_sales,
            AVG(t.total_amount) as avg_transaction
     FROM transactions t
     WHERE t.status = 'completed' AND DATE(t.transaction_date) BETWEEN ? AND ?
     GROUP BY DATE(t.transaction_date)
     ORDER BY date ASC",
    [$date_from, $date_to]
);

// Peak sales hours (heatmap data)
$peak_hours = $db->fetchAll(
    "SELECT HOUR(t.transaction_date) as hour,
            DAYNAME(t.transaction_date) as day_name,
            COUNT(t.id) as transactions,
            SUM(t.total_amount) as total_sales
     FROM transactions t
     WHERE t.status = 'completed' AND DATE(t.transaction_date) BETWEEN ? AND ?
     GROUP BY HOUR(t.transaction_date), DAYNAME(t.transaction_date)
     ORDER BY hour ASC",
    [$date_from, $date_to]
);

// Average transaction value and items per transaction
$avg_metrics = $db->fetch(
    "SELECT 
        COUNT(DISTINCT t.id) as total_transactions,
        AVG(t.total_amount) as avg_transaction_value,
        AVG(item_counts.item_count) as avg_items_per_transaction,
        SUM(t.total_amount) as total_revenue,
        MAX(t.total_amount) as max_transaction,
        MIN(t.total_amount) as min_transaction
     FROM transactions t
     LEFT JOIN (
        SELECT transaction_id, COUNT(*) as item_count
        FROM transaction_items
        GROUP BY transaction_id
     ) item_counts ON t.id = item_counts.transaction_id
     WHERE t.status = 'completed' AND DATE(t.transaction_date) BETWEEN ? AND ?",
    [$date_from, $date_to]
);

// Period comparison
$compare_data = null;
if (!empty($compare_from) && !empty($compare_to)) {
    $compare_data = $db->fetch(
        "SELECT 
            COUNT(DISTINCT id) as total_transactions,
            SUM(total_amount) as total_revenue,
            AVG(total_amount) as avg_transaction
         FROM transactions
         WHERE status = 'completed' AND DATE(transaction_date) BETWEEN ? AND ?",
        [$compare_from, $compare_to]
    );
}

// Prepare data for charts
$category_labels = array_map(fn($c) => $c['name'], $by_category);
$category_data = array_map(fn($c) => $c['total_revenue'], $by_category);

$brand_labels = array_map(fn($b) => $b['brand'] ?? 'Unknown', $by_brand);
$brand_data = array_map(fn($b) => $b['total_revenue'], $by_brand);

$trend_dates = array_map(fn($t) => substr($t['date'], 5), $trends);
$trend_sales = array_map(fn($t) => $t['total_sales'], $trends);

$heatmap_hours = [];
$heatmap_data = [];
foreach ($peak_hours as $ph) {
    $key = sprintf('%02d:00', $ph['hour']);
    if (!isset($heatmap_data[$key])) {
        $heatmap_data[$key] = $ph['transactions'];
    }
}
$heatmap_hours = array_keys($heatmap_data);
$heatmap_values = array_values($heatmap_data);

include 'templates/header.php';
?>

<div class="row mb-4">
    <div class="col-12">
        <h1 class="h3 mb-4">Sales Analytics</h1>
        
        <!-- Date Range Filter -->
        <div class="card mb-4">
            <div class="card-header">Filter by Date Range</div>
            <div class="card-body">
                <form method="GET" class="row g-3">
                    <div class="col-md-2">
                        <label class="form-label">From</label>
                        <input type="date" class="form-control" name="from" value="<?php echo escape($date_from); ?>">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">To</label>
                        <input type="date" class="form-control" name="to" value="<?php echo escape($date_to); ?>">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Period</label>
                        <select class="form-select" name="period">
                            <option value="daily" <?php echo $period === 'daily' ? 'selected' : ''; ?>>Daily</option>
                            <option value="weekly" <?php echo $period === 'weekly' ? 'selected' : ''; ?>>Weekly</option>
                            <option value="monthly" <?php echo $period === 'monthly' ? 'selected' : ''; ?>>Monthly</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">&nbsp;</label>
                        <button type="submit" class="btn btn-primary w-100">Filter</button>
                    </div>
                    <div class="col-md-4">
                        <small class="text-muted d-block mt-4">
                            Showing data from <strong><?php echo $date_from; ?></strong> to <strong><?php echo $date_to; ?></strong>
                        </small>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Key Metrics -->
<div class="row mb-4">
    <div class="col-md-3">
        <div class="card">
            <div class="card-body">
                <div class="text-muted small">Total Revenue</div>
                <div class="h3 mb-0 text-success"><?php echo formatCurrency($avg_metrics['total_revenue'] ?? 0); ?></div>
                <small class="text-muted"><?php echo $avg_metrics['total_transactions'] ?? 0; ?> transactions</small>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card">
            <div class="card-body">
                <div class="text-muted small">Avg Transaction</div>
                <div class="h3 mb-0" style="color: #0066cc;"><?php echo formatCurrency($avg_metrics['avg_transaction_value'] ?? 0); ?></div>
                <small class="text-muted"><?php echo formatDecimal($avg_metrics['avg_items_per_transaction'] ?? 0, 1); ?> items/tx avg</small>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card">
            <div class="card-body">
                <div class="text-muted small">Min/Max Transaction</div>
                <div class="h5 mb-0">
                    <span style="color: #dc3545;"><?php echo formatCurrency($avg_metrics['min_transaction'] ?? 0); ?></span>
                    /
                    <span style="color: #28a745;"><?php echo formatCurrency($avg_metrics['max_transaction'] ?? 0); ?></span>
                </div>
                <small class="text-muted">Range</small>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card">
            <div class="card-body">
                <div class="text-muted small">Top Category</div>
                <div class="h5 mb-0">
                    <?php echo escape($by_category[0]['name'] ?? 'N/A'); ?>
                </div>
                <small class="text-muted">
                    <?php echo formatCurrency($by_category[0]['total_revenue'] ?? 0); ?>
                </small>
            </div>
        </div>
    </div>
</div>

<!-- Charts Row 1 -->
<div class="row mb-4">
    <div class="col-lg-6">
        <div class="card">
            <div class="card-header">Sales by Category</div>
            <div class="card-body">
                <canvas id="categoryChart" style="max-height: 350px;"></canvas>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card">
            <div class="card-header">Sales by Brand (Top 15)</div>
            <div class="card-body">
                <canvas id="brandChart" style="max-height: 350px;"></canvas>
            </div>
        </div>
    </div>
</div>

<!-- Charts Row 2 -->
<div class="row mb-4">
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header">Sales Trend</div>
            <div class="card-body">
                <canvas id="trendChart" style="max-height: 300px;"></canvas>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header">Peak Sales Hours</div>
            <div class="card-body">
                <canvas id="hoursChart" style="max-height: 300px;"></canvas>
            </div>
        </div>
    </div>
</div>

<!-- Category Table -->
<div class="card mb-4">
    <div class="card-header">Category Breakdown</div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>Category</th>
                        <th>Transactions</th>
                        <th>Units Sold</th>
                        <th>Total Revenue</th>
                        <th>% of Total</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $total_cat_revenue = array_sum(array_map(fn($c) => $c['total_revenue'], $by_category));
                    foreach ($by_category as $cat):
                    ?>
                    <tr>
                        <td><strong><?php echo escape($cat['name']); ?></strong></td>
                        <td><?php echo $cat['transactions']; ?></td>
                        <td><?php echo formatDecimal($cat['total_qty'], 0); ?></td>
                        <td><?php echo formatCurrency($cat['total_revenue']); ?></td>
                        <td><?php echo number_format(($cat['total_revenue'] / $total_cat_revenue) * 100, 1); ?>%</td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Period Comparison (if comparing) -->
<?php if ($compare_data): ?>
<div class="card mb-4">
    <div class="card-header">Period Comparison</div>
    <div class="card-body">
        <div class="row">
            <div class="col-md-6">
                <h6>Current Period (<?php echo $date_from; ?> to <?php echo $date_to; ?>)</h6>
                <ul class="list-unstyled">
                    <li>Transactions: <strong><?php echo $avg_metrics['total_transactions']; ?></strong></li>
                    <li>Revenue: <strong><?php echo formatCurrency($avg_metrics['total_revenue'] ?? 0); ?></strong></li>
                    <li>Avg Transaction: <strong><?php echo formatCurrency($avg_metrics['avg_transaction_value'] ?? 0); ?></strong></li>
                </ul>
            </div>
            <div class="col-md-6">
                <h6>Comparison Period (<?php echo $compare_from; ?> to <?php echo $compare_to; ?>)</h6>
                <ul class="list-unstyled">
                    <li>Transactions: <strong><?php echo $compare_data['total_transactions']; ?></strong></li>
                    <li>Revenue: <strong><?php echo formatCurrency($compare_data['total_revenue'] ?? 0); ?></strong></li>
                    <li>Avg Transaction: <strong><?php echo formatCurrency($compare_data['avg_transaction'] ?? 0); ?></strong></li>
                </ul>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Chart.js Library -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>

<script>
// Helper
function formatMoney(value) {
    return new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format(value || 0);
}

// Category Sales Bar Chart
const categoryCtx = document.getElementById('categoryChart').getContext('2d');
new Chart(categoryCtx, {
    type: 'bar',
    data: {
        labels: <?php echo json_encode($category_labels); ?>,
        datasets: [{
            label: 'Revenue',
            data: <?php echo json_encode($category_data); ?>,
            backgroundColor: '#36A2EB',
            borderColor: '#2874B6',
            borderWidth: 1
        }]
    },
    options: {
        indexAxis: 'y',
        responsive: true,
        plugins: {
            legend: { display: false },
            tooltip: { callbacks: { label: ctx => formatMoney(ctx.parsed.x) } }
        },
        scales: {
            x: { ticks: { callback: value => formatMoney(value) } }
        }
    }
});

// Brand Sales Pie Chart
const brandCtx = document.getElementById('brandChart').getContext('2d');
new Chart(brandCtx, {
    type: 'doughnut',
    data: {
        labels: <?php echo json_encode($brand_labels); ?>,
        datasets: [{
            data: <?php echo json_encode($brand_data); ?>,
            backgroundColor: [
                '#FF6384', '#36A2EB', '#FFCE56', '#4BC0C0', '#9966FF',
                '#FF9F40', '#FF6384', '#C9CBCF', '#FF6384', '#36A2EB',
                '#FFCE56', '#4BC0C0', '#9966FF', '#FF9F40', '#C9CBCF'
            ],
            borderWidth: 2,
            borderColor: '#fff'
        }]
    },
    options: {
        responsive: true,
        plugins: {
            legend: { position: 'right' },
            tooltip: { callbacks: { label: ctx => formatMoney(ctx.parsed) } }
        }
    }
});

// Sales Trend Line Chart
const trendCtx = document.getElementById('trendChart').getContext('2d');
new Chart(trendCtx, {
    type: 'line',
    data: {
        labels: <?php echo json_encode($trend_dates); ?>,
        datasets: [{
            label: 'Daily Sales',
            data: <?php echo json_encode($trend_sales); ?>,
            borderColor: '#28a745',
            backgroundColor: 'rgba(40, 167, 69, 0.1)',
            borderWidth: 2,
            fill: true,
            tension: 0.4
        }]
    },
    options: {
        responsive: true,
        plugins: {
            legend: { display: false },
            tooltip: { callbacks: { label: ctx => formatMoney(ctx.parsed.y) } }
        },
        scales: {
            y: { beginning: true, ticks: { callback: value => formatMoney(value) } }
        }
    }
});

// Peak Hours Bar Chart
const hoursCtx = document.getElementById('hoursChart').getContext('2d');
new Chart(hoursCtx, {
    type: 'bar',
    data: {
        labels: <?php echo json_encode($heatmap_hours); ?>,
        datasets: [{
            label: 'Transactions',
            data: <?php echo json_encode($heatmap_values); ?>,
            backgroundColor: '#FF7300',
            borderColor: '#CC5C00',
            borderWidth: 1
        }]
    },
    options: {
        indexAxis: 'y',
        responsive: true,
        plugins: { legend: { display: false } },
        scales: { x: { beginAtZero: true } }
    }
});
</script>

<?php include 'templates/footer.php'; ?>
