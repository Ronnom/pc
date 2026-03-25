<?php
/**
 * Daily Sales Dashboard
 * Summary metrics, payment breakdown, cashier performance, hourly sales, top products
 */

require_once 'includes/init.php';
requireLogin();
requirePermission('sales.view');

$db = getDB();
$date = trim($_GET['date'] ?? date('Y-m-d'));
$view = trim($_GET['view'] ?? 'daily'); // daily, weekly, monthly

// Validate date format
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    $date = date('Y-m-d');
}

// Date calculations
$start_date = $date;
$end_date = $date;
if ($view === 'weekly') {
    $start_date = date('Y-m-d', strtotime($date . ' -6 days'));
    $end_date = $date;
} elseif ($view === 'monthly') {
    $start_date = date('Y-m-01', strtotime($date));
    $end_date = date('Y-m-t', strtotime($date));
}

// Get summary metrics
$summary = $db->fetchOne(
    "SELECT 
        COUNT(DISTINCT id) as num_transactions,
        SUM(CASE WHEN status = 'completed' THEN subtotal ELSE 0 END) as subtotal,
        SUM(CASE WHEN status = 'completed' THEN tax_amount ELSE 0 END) as total_tax,
        SUM(CASE WHEN status = 'completed' THEN discount_amount ELSE 0 END) as total_discount,
        SUM(CASE WHEN status = 'completed' THEN total_amount ELSE 0 END) as total_sales,
        COUNT(CASE WHEN status = 'completed' THEN 1 END) as completed_transactions,
        COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending_transactions,
        COUNT(CASE WHEN status = 'voided' THEN 1 END) as voided_transactions
     FROM transactions
     WHERE DATE(transaction_date) BETWEEN ? AND ?",
    [$start_date, $end_date]
);

// Calculate average transaction value
$avg_transaction = ($summary['completed_transactions'] > 0) 
    ? ($summary['total_sales'] / $summary['completed_transactions']) 
    : 0;

// Get items sold
$items_sold = $db->fetchOne(
    "SELECT SUM(ti.quantity) as total_items
     FROM transaction_items ti
     JOIN transactions t ON ti.transaction_id = t.id
     WHERE DATE(t.transaction_date) BETWEEN ? AND ? AND t.status = 'completed'",
    [$start_date, $end_date]
);

// Payment method breakdown
$payment_breakdown = $db->fetchAll(
    "SELECT p.payment_method, SUM(p.amount) as total, COUNT(DISTINCT p.transaction_id) as count
     FROM payments p
     JOIN transactions t ON p.transaction_id = t.id
     WHERE DATE(t.transaction_date) BETWEEN ? AND ? AND t.status = 'completed'
     GROUP BY p.payment_method
     ORDER BY total DESC",
    [$start_date, $end_date]
);

// Cashier performance
$cashier_performance = $db->fetchAll(
    "SELECT u.id, CONCAT(u.first_name, ' ', u.last_name) as cashier_name,
            COUNT(t.id) as transactions, 
            SUM(t.total_amount) as total_sales,
            AVG(t.total_amount) as avg_transaction,
            SUM(CASE WHEN t.status = 'completed' THEN 1 ELSE 0 END) as completed
      FROM users u
      LEFT JOIN transactions t ON u.id = t.user_id AND DATE(t.transaction_date) BETWEEN ? AND ?
      LEFT JOIN roles r ON u.role_id = r.id
      WHERE r.name IN ('cashier', 'manager', 'admin')
      GROUP BY u.id
      ORDER BY total_sales DESC",
    [$start_date, $end_date]
);

// Hourly sales breakdown (for daily view)
$hourly_sales = $db->fetchAll(
    "SELECT HOUR(t.transaction_date) as hour, 
            COUNT(t.id) as transactions,
            SUM(t.total_amount) as total_sales
     FROM transactions t
     WHERE DATE(t.transaction_date) BETWEEN ? AND ? AND t.status = 'completed'
     GROUP BY HOUR(t.transaction_date)
     ORDER BY hour ASC",
    [$start_date, $end_date]
);

// Top 10 products
$top_products = $db->fetchAll(
    "SELECT p.id, p.sku as product_code, p.name,
            SUM(ti.quantity) as qty_sold,
            SUM(ti.total) as total_revenue
     FROM transaction_items ti
     JOIN products p ON ti.product_id = p.id
     JOIN transactions t ON ti.transaction_id = t.id
     WHERE DATE(t.transaction_date) BETWEEN ? AND ? AND t.status = 'completed'
     GROUP BY p.id
     ORDER BY qty_sold DESC
     LIMIT 10",
    [$start_date, $end_date]
);

// Prepare data for charts
$payment_labels = array_map(fn($p) => ucfirst(str_replace('_', ' ', $p['payment_method'])), $payment_breakdown);
$payment_data = array_map(fn($p) => $p['total'], $payment_breakdown);

$hourly_labels = array_map(fn($h) => sprintf('%02d:00', $h['hour']), $hourly_sales);
$hourly_data = array_map(fn($h) => $h['total_sales'], $hourly_sales);

$cashier_labels = array_map(fn($c) => $c['cashier_name'], $cashier_performance);
$cashier_data = array_map(fn($c) => $c['total_sales'] ?? 0, $cashier_performance);

include 'templates/header.php';
?>

<div class="row mb-4">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center">
            <h1 class="h3 mb-0">Daily Sales Dashboard</h1>
            <div class="btn-group" role="group">
                <input type="radio" class="btn-check" name="view" id="daily" value="daily" onchange="changeView('daily')" <?php echo $view === 'daily' ? 'checked' : ''; ?>>
                <label class="btn btn-outline-primary" for="daily">Daily</label>
                
                <input type="radio" class="btn-check" name="view" id="weekly" value="weekly" onchange="changeView('weekly')" <?php echo $view === 'weekly' ? 'checked' : ''; ?>>
                <label class="btn btn-outline-primary" for="weekly">Weekly</label>
                
                <input type="radio" class="btn-check" name="view" id="monthly" value="monthly" onchange="changeView('monthly')" <?php echo $view === 'monthly' ? 'checked' : ''; ?>>
                <label class="btn btn-outline-primary" for="monthly">Monthly</label>
            </div>
            <input type="date" class="form-control" style="width: 150px;" value="<?php echo escape($date); ?>" onchange="changeDate(this.value)">
        </div>
    </div>
</div>

<!-- Key Metrics -->
<div class="row mb-4">
    <div class="col-md-3">
        <div class="card">
            <div class="card-body">
                <div class="text-muted small">Total Sales</div>
                <div class="h3 mb-0 text-success"><?php echo formatCurrency($summary['total_sales'] ?? 0); ?></div>
                <small class="text-muted"><?php echo $summary['completed_transactions']; ?> transactions</small>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card">
            <div class="card-body">
                <div class="text-muted small">Avg Transaction</div>
                <div class="h3 mb-0" style="color: #0066cc;"><?php echo formatCurrency($avg_transaction); ?></div>
                <small class="text-muted"><?php echo formatDecimal($items_sold['total_items'] ?? 0, 0); ?> items sold</small>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card">
            <div class="card-body">
                <div class="text-muted small">Tax Collected</div>
                <div class="h3 mb-0" style="color: #6f42c1;"><?php echo formatCurrency($summary['total_tax'] ?? 0); ?></div>
                <small class="text-muted">Discount: <?php echo formatCurrency($summary['total_discount'] ?? 0); ?></small>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card">
            <div class="card-body">
                <div class="text-muted small">Pending/Voided</div>
                <div class="h3 mb-0" style="color: #dc3545;">
                    <?php echo $summary['pending_transactions']; ?>/<?php echo $summary['voided_transactions']; ?>
                </div>
                <small class="text-muted">Transactions</small>
            </div>
        </div>
    </div>
</div>

<!-- Charts -->
<div class="row mb-4">
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">Payment Methods</div>
            <div class="card-body">
                <canvas id="paymentChart" style="max-height: 300px;"></canvas>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">Hourly Sales</div>
            <div class="card-body">
                <canvas id="hourlyChart" style="max-height: 300px;"></canvas>
            </div>
        </div>
    </div>
</div>

<!-- Cashier Performance -->
<div class="card mb-4">
    <div class="card-header">Cashier Performance</div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>Cashier</th>
                        <th>Transactions</th>
                        <th>Total Sales</th>
                        <th>Avg Transaction</th>
                        <th>Completed %</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($cashier_performance as $cashier): ?>
                    <tr>
                        <td><strong><?php echo escape($cashier['cashier_name']); ?></strong></td>
                        <td><?php echo $cashier['transactions'] ?? 0; ?></td>
                        <td><?php echo formatCurrency($cashier['total_sales'] ?? 0); ?></td>
                        <td><?php echo formatCurrency($cashier['avg_transaction'] ?? 0); ?></td>
                        <td>
                            <?php 
                            $total = $cashier['transactions'] ?? 0;
                            $completed = $cashier['completed'] ?? 0;
                            $percentage = $total > 0 ? (($completed / $total) * 100) : 0;
                            echo number_format($percentage, 1) . '%';
                            ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Top 10 Products -->
<div class="card">
    <div class="card-header">Top 10 Products</div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>Product</th>
                        <th>SKU</th>
                        <th>Qty Sold</th>
                        <th>Revenue</th>
                        <th>Avg Price</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($top_products as $product): ?>
                    <tr>
                        <td><strong><?php echo escape($product['name']); ?></strong></td>
                        <td><code><?php echo escape($product['product_code']); ?></code></td>
                        <td><?php echo formatDecimal($product['qty_sold'], 0); ?></td>
                        <td><?php echo formatCurrency($product['total_revenue']); ?></td>
                        <td><?php echo formatCurrency($product['total_revenue'] / ($product['qty_sold'] > 0 ? $product['qty_sold'] : 1)); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Chart.js Library -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>

<script>
// Format currency helper
function formatMoney(value) {
    return new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format(value || 0);
}

// Payment Methods Pie Chart
const paymentCtx = document.getElementById('paymentChart').getContext('2d');
new Chart(paymentCtx, {
    type: 'pie',
    data: {
        labels: <?php echo json_encode($payment_labels); ?>,
        datasets: [{
            data: <?php echo json_encode($payment_data); ?>,
            backgroundColor: [
                '#FF6384', '#36A2EB', '#FFCE56', '#4BC0C0', '#9966FF',
                '#FF9F40', '#FF6384', '#C9CBCF'
            ],
            borderWidth: 2,
            borderColor: '#fff'
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: true,
        plugins: {
            legend: { position: 'bottom' },
            tooltip: { callbacks: { label: ctx => formatMoney(ctx.parsed) } }
        }
    }
});

// Hourly Sales Line Chart
<?php if (!empty($hourly_sales)): ?>
const hourlyCtx = document.getElementById('hourlyChart').getContext('2d');
new Chart(hourlyCtx, {
    type: 'line',
    data: {
        labels: <?php echo json_encode($hourly_labels); ?>,
        datasets: [{
            label: 'Sales',
            data: <?php echo json_encode($hourly_data); ?>,
            borderColor: '#36A2EB',
            backgroundColor: 'rgba(54, 162, 235, 0.1)',
            borderWidth: 2,
            fill: true,
            tension: 0.4,
            pointRadius: 4,
            pointBackgroundColor: '#36A2EB'
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: true,
        plugins: {
            legend: { display: false },
            tooltip: { callbacks: { label: ctx => formatMoney(ctx.parsed.y) } }
        },
        scales: {
            y: {
                beginAtZero: true,
                ticks: { callback: value => formatMoney(value) }
            }
        }
    }
});
<?php endif; ?>

// View and date change functions
function changeView(view) {
    const date = document.querySelector('input[type="date"]').value;
    window.location.href = '<?php echo getBaseUrl(); ?>/daily_sales.php?view=' + view + '&date=' + date;
}

function changeDate(date) {
    const view = document.querySelector('input[name="view"]:checked').value;
    window.location.href = '<?php echo getBaseUrl(); ?>/daily_sales.php?view=' + view + '&date=' + date;
}
</script>

<?php include 'templates/footer.php'; ?>
