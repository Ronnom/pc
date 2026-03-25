<?php
/**
 * Sales Reports
 * Daily, weekly, monthly sales analysis with breakdown by product, category, and cashier
 */

require_once 'includes/init.php';
requireLogin();
requirePermission('reports.view');

$db = getDB();
$report_type = trim($_GET['type'] ?? 'daily');
$date_from = trim($_GET['from'] ?? date('Y-m-01'));
$date_to = trim($_GET['to'] ?? date('Y-m-d'));

// Date presets
$today = date('Y-m-d');
$yesterday = date('Y-m-d', strtotime('-1 day'));
$week_start = date('Y-m-d', strtotime('monday this week'));
$month_start = date('Y-m-01');
$last_month_start = date('Y-m-01', strtotime('-1 month'));
$last_month_end = date('Y-m-t', strtotime('-1 month'));

// Handle preset selection
if (isset($_GET['preset'])) {
    $preset = trim($_GET['preset']);
    switch ($preset) {
        case 'today': $date_from = $date_to = $today; break;
        case 'yesterday': $date_from = $date_to = $yesterday; break;
        case 'this_week': $date_from = $week_start; $date_to = $today; break;
        case 'last_week': $date_from = date('Y-m-d', strtotime('-7 days', strtotime($week_start))); $date_to = date('Y-m-d', strtotime('-1 day', strtotime($week_start))); break;
        case 'this_month': $date_from = $month_start; $date_to = $today; break;
        case 'last_month': $date_from = $last_month_start; $date_to = $last_month_end; break;
    }
}

// DAILY SALES REPORT
if ($report_type === 'daily') {
    $daily_sales = $db->fetchAll(
        "SELECT 
            DATE(transaction_date) as date,
            COUNT(*) as transaction_count,
            COALESCE(SUM(total_amount), 0) as total_sales,
            COALESCE(SUM(tax_amount), 0) as tax_amount,
            COALESCE(SUM(discount_amount), 0) as discount_amount,
            COALESCE(AVG(total_amount), 0) as avg_transaction
         FROM transactions
         WHERE status = 'completed' AND DATE(transaction_date) BETWEEN ? AND ?
         GROUP BY DATE(transaction_date)
         ORDER BY date DESC",
        [$date_from, $date_to]
    );
    
    $payment_breakdown = $db->fetchAll(
        "SELECT p.payment_method, COUNT(*) as count, COALESCE(SUM(p.amount), 0) as total
         FROM payments p
         JOIN transactions t ON p.transaction_id = t.id
         WHERE t.status = 'completed' AND DATE(t.transaction_date) BETWEEN ? AND ?
         GROUP BY p.payment_method
         ORDER BY total DESC",
        [$date_from, $date_to]
    );
    
    $cashier_summary = $db->fetchAll(
        "SELECT COALESCE(NULLIF(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, '')), ' '), u.username, 'Unknown') as display_name,
                COUNT(DISTINCT t.id) as transaction_count,
                COALESCE(SUM(t.total_amount), 0) as total
         FROM transactions t
         LEFT JOIN users u ON t.user_id = u.id
         WHERE t.status = 'completed' AND DATE(t.transaction_date) BETWEEN ? AND ?
         GROUP BY t.user_id, u.first_name, u.last_name, u.username
         ORDER BY total DESC",
        [$date_from, $date_to]
    );
    
    $hourly_sales = $db->fetchAll(
        "SELECT HOUR(transaction_date) as hour, 
                COUNT(*) as count, 
                COALESCE(SUM(total_amount), 0) as total
         FROM transactions
         WHERE status = 'completed' AND DATE(transaction_date) BETWEEN ? AND ?
         GROUP BY HOUR(transaction_date)
         ORDER BY hour ASC",
        [$date_from, $date_to]
    );
}

// SALES BY PRODUCT
elseif ($report_type === 'by_product') {
    $product_sales = $db->fetchAll(
        "SELECT p.id, p.name, p.category_id,
                COALESCE(SUM(ti.quantity), 0) as qty_sold,
                COALESCE(SUM(ti.quantity * ti.unit_price), 0) as revenue,
                COALESCE(SUM(ti.quantity * COALESCE(ti.serial_cost_price, p.cost_price)), 0) as cost,
                COALESCE(AVG(ti.unit_price), 0) as avg_price
         FROM transaction_items ti
         JOIN products p ON ti.product_id = p.id
         JOIN transactions t ON ti.transaction_id = t.id
         WHERE t.status = 'completed' AND DATE(t.transaction_date) BETWEEN ? AND ?
         GROUP BY p.id, p.name, p.category_id
         ORDER BY revenue DESC",
        [$date_from, $date_to]
    );
}

// SALES BY CATEGORY
elseif ($report_type === 'by_category') {
    $category_sales = $db->fetchAll(
        "SELECT c.id, c.name,
                COUNT(DISTINCT t.id) as transaction_count,
                COALESCE(SUM(ti.quantity), 0) as qty_sold,
                COALESCE(SUM(ti.quantity * ti.unit_price), 0) as revenue,
                COALESCE(SUM(ti.quantity * COALESCE(ti.serial_cost_price, p.cost_price)), 0) as cost,
                ROUND(100.0 * SUM(ti.quantity * ti.unit_price) / (SELECT SUM(total_amount) FROM transactions WHERE status = 'completed' AND DATE(transaction_date) BETWEEN ? AND ?), 2) as pct_of_total
         FROM transaction_items ti
         JOIN products p ON ti.product_id = p.id
         JOIN categories c ON p.category_id = c.id
         JOIN transactions t ON ti.transaction_id = t.id
         WHERE t.status = 'completed' AND DATE(t.transaction_date) BETWEEN ? AND ?
         GROUP BY c.id, c.name
         ORDER BY revenue DESC",
        [$date_from, $date_to, $date_from, $date_to]
    );
}

// COMPARISON (last period)
elseif ($report_type === 'comparison') {
    $period_days = (int)((new DateTime($date_to))->diff(new DateTime($date_from))->days) + 1;
    $comp_date_from = date('Y-m-d', strtotime("-$period_days days", strtotime($date_from)));
    $comp_date_to = date('Y-m-d', strtotime('-1 day', strtotime($date_from)));
    
    $current_period = $db->fetchOne(
        "SELECT COUNT(*) as count, COALESCE(SUM(total_amount), 0) as total, COALESCE(AVG(total_amount), 0) as avg
         FROM transactions WHERE status = 'completed' AND DATE(transaction_date) BETWEEN ? AND ?",
        [$date_from, $date_to]
    );
    
    $previous_period = $db->fetchOne(
        "SELECT COUNT(*) as count, COALESCE(SUM(total_amount), 0) as total, COALESCE(AVG(total_amount), 0) as avg
         FROM transactions WHERE status = 'completed' AND DATE(transaction_date) BETWEEN ? AND ?",
        [$comp_date_from, $comp_date_to]
    );
}

$pageTitle = 'Sales Reports';
include 'templates/header.php';
?>

<div class="container-fluid">
<div class="row mb-4">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center">
            <h1 class="h3 mb-0">Sales Reports</h1>
            <a href="<?php echo getBaseUrl(); ?>/reports.php" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left"></i> Back
            </a>
        </div>
    </div>
</div>

<!-- REPORT TYPE TABS -->
<ul class="nav nav-tabs mb-4">
    <li class="nav-item">
        <a class="nav-link <?php echo $report_type === 'daily' ? 'active' : ''; ?>" href="?type=daily&from=<?php echo $date_from; ?>&to=<?php echo $date_to; ?>">Daily Sales</a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?php echo $report_type === 'by_product' ? 'active' : ''; ?>" href="?type=by_product&from=<?php echo $date_from; ?>&to=<?php echo $date_to; ?>">By Product</a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?php echo $report_type === 'by_category' ? 'active' : ''; ?>" href="?type=by_category&from=<?php echo $date_from; ?>&to=<?php echo $date_to; ?>">By Category</a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?php echo $report_type === 'comparison' ? 'active' : ''; ?>" href="?type=comparison&from=<?php echo $date_from; ?>&to=<?php echo $date_to; ?>">Comparison</a>
    </li>
</ul>

<!-- DATE FILTER -->
<div class="card mb-4">
    <div class="card-body">
        <form class="row g-3" method="GET">
            <input type="hidden" name="type" value="<?php echo escape($report_type); ?>">
            <div class="col-md-3">
                <label class="form-label">From Date</label>
                <input type="date" class="form-control" name="from" value="<?php echo escape($date_from); ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label">To Date</label>
                <input type="date" class="form-control" name="to" value="<?php echo escape($date_to); ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label">Quick Presets</label>
                <select class="form-control" onchange="this.form.preset.value = this.value; this.form.submit();">
                    <option value="">-- Select --</option>
                    <option value="today">Today</option>
                    <option value="yesterday">Yesterday</option>
                    <option value="this_week">This Week</option>
                    <option value="this_month">This Month</option>
                    <option value="last_month">Last Month</option>
                </select>
                <input type="hidden" name="preset" value="">
            </div>
            <div class="col-md-3 d-flex align-items-end">
                <button type="submit" class="btn btn-primary w-100">Filter</button>
            </div>
        </form>
    </div>
</div>

<!-- DAILY SALES -->
<?php if ($report_type === 'daily'): ?>
<div class="card mb-4">
    <div class="card-header">Daily Sales Summary</div>
    <div class="card-body">
        <?php
        $daily_sales_headers = [
            ['label' => 'Date', 'sortable' => true, 'key' => 'date'],
            ['label' => 'Transactions', 'sortable' => true, 'key' => 'transaction_count', 'class' => 'text-end'],
            ['label' => 'Total Sales', 'sortable' => true, 'key' => 'total_sales', 'class' => 'text-end'],
            ['label' => 'Tax', 'sortable' => true, 'key' => 'tax_amount', 'class' => 'text-end'],
            ['label' => 'Discounts', 'sortable' => true, 'key' => 'discount_amount', 'class' => 'text-end'],
            ['label' => 'Avg Per Tx', 'sortable' => true, 'key' => 'avg_transaction', 'class' => 'text-end']
        ];

        $daily_sales_data = array_map(function($day) {
            return [
                'date' => formatDate($day['date']),
                'transaction_count' => (int)$day['transaction_count'],
                'total_sales' => formatCurrency($day['total_sales']),
                'tax_amount' => formatCurrency($day['tax_amount']),
                'discount_amount' => formatCurrency($day['discount_amount']),
                'avg_transaction' => formatCurrency($day['avg_transaction'])
            ];
        }, $daily_sales);

        echo renderTable($daily_sales_headers, $daily_sales_data, [
            'empty_message' => 'No sales data found for the selected period.',
            'striped' => true,
            'hover' => true,
            'compact' => true
        ]);
        ?>
    </div>
</div>

<div class="row">
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">Payment Methods</div>
            <div class="card-body">
                <?php
                $payment_headers = [
                    ['label' => 'Method', 'sortable' => true, 'key' => 'payment_method'],
                    ['label' => 'Count', 'sortable' => true, 'key' => 'count', 'class' => 'text-end'],
                    ['label' => 'Total', 'sortable' => true, 'key' => 'total', 'class' => 'text-end']
                ];

                $payment_data = array_map(function($payment) {
                    return [
                        'payment_method' => ucfirst(str_replace('_', ' ', $payment['payment_method'])),
                        'count' => (int)$payment['count'],
                        'total' => formatCurrency($payment['total'])
                    ];
                }, $payment_breakdown);

                // Add total row
                $total_payments = array_sum(array_column($payment_breakdown, 'total'));
                $payment_data[] = [
                    'payment_method' => '<strong>Total</strong>',
                    'count' => '<strong>-</strong>',
                    'total' => '<strong>' . formatCurrency($total_payments) . '</strong>'
                ];

                echo renderTable($payment_headers, $payment_data, [
                    'empty_message' => 'No payment data found.',
                    'striped' => true,
                    'hover' => true,
                    'compact' => true,
                    'last_row_highlighted' => true
                ]);
                ?>
            </div>
        </div>
    </div>
    
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">Cashier Summary</div>
            <div class="card-body">
                <?php
                $cashier_headers = [
                    ['label' => 'Cashier', 'sortable' => true, 'key' => 'display_name'],
                    ['label' => 'Transactions', 'sortable' => true, 'key' => 'transaction_count', 'class' => 'text-end'],
                    ['label' => 'Total', 'sortable' => true, 'key' => 'total', 'class' => 'text-end']
                ];

                $cashier_data = array_map(function($cashier) {
                    return [
                        'display_name' => escape($cashier['display_name'] ?? 'Unknown'),
                        'transaction_count' => (int)$cashier['transaction_count'],
                        'total' => formatCurrency($cashier['total'])
                    ];
                }, $cashier_summary);

                echo renderTable($cashier_headers, $cashier_data, [
                    'empty_message' => 'No cashier data found.',
                    'striped' => true,
                    'hover' => true,
                    'compact' => true
                ]);
                ?>
            </div>
        </div>
    </div>
</div>

<?php endif; ?>

<!-- SALES BY PRODUCT -->
<?php if ($report_type === 'by_product'): ?>
<div class="card">
    <div class="card-header">Product Sales Performance</div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-sm">
                <thead>
                    <tr>
                        <th>Product</th>
                        <th class="text-end">Qty Sold</th>
                        <th class="text-end">Avg Price</th>
                        <th class="text-end">Revenue</th>
                        <th class="text-end">Cost</th>
                        <th class="text-end">Profit</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($product_sales as $product): $profit = $product['revenue'] - $product['cost']; ?>
                    <tr>
                        <td><?php echo escape($product['name']); ?></td>
                        <td class="text-end"><?php echo (int)$product['qty_sold']; ?></td>
                        <td class="text-end"><?php echo formatCurrency($product['avg_price']); ?></td>
                        <td class="text-end"><?php echo formatCurrency($product['revenue']); ?></td>
                        <td class="text-end"><?php echo formatCurrency($product['cost']); ?></td>
                        <td class="text-end text-success"><?php echo formatCurrency($profit); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php endif; ?>

<!-- SALES BY CATEGORY -->
<?php if ($report_type === 'by_category'): ?>
<div class="card">
    <div class="card-header">Category Performance</div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-sm">
                <thead>
                    <tr>
                        <th>Category</th>
                        <th class="text-end">Transactions</th>
                        <th class="text-end">Qty Sold</th>
                        <th class="text-end">Revenue</th>
                        <th class="text-end">% of Total</th>
                        <th class="text-end">Profit</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($category_sales as $category): $profit = $category['revenue'] - $category['cost']; ?>
                    <tr>
                        <td><?php echo escape($category['name']); ?></td>
                        <td class="text-end"><?php echo (int)$category['transaction_count']; ?></td>
                        <td class="text-end"><?php echo (int)$category['qty_sold']; ?></td>
                        <td class="text-end"><?php echo formatCurrency($category['revenue']); ?></td>
                        <td class="text-end"><?php echo $category['pct_of_total']; ?>%</td>
                        <td class="text-end text-success"><?php echo formatCurrency($profit); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php endif; ?>

<!-- COMPARISON -->
<?php if ($report_type === 'comparison' && isset($current_period)): ?>
<div class="row">
    <div class="col-md-4">
        <div class="card text-center">
            <div class="card-body">
                <h6 class="card-title">Current Period Transactions</h6>
                <div class="display-5"><?php echo (int)$current_period['count']; ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card text-center">
            <div class="card-body">
                <h6 class="card-title">Current Period Revenue</h6>
                <div class="display-5"><?php echo formatCurrency($current_period['total']); ?></div>
                <small class="text-muted">Avg: <?php echo formatCurrency($current_period['avg']); ?></small>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card text-center">
            <div class="card-body">
                <h6 class="card-title">Previous Period</h6>
                <div class="display-5"><?php echo formatCurrency($previous_period['total']); ?></div>
                <small class="text-muted text-<?php echo ($current_period['total'] >= $previous_period['total'] ? 'success' : 'danger'); ?>">
                    <?php $growth = (($current_period['total'] - $previous_period['total']) / max($previous_period['total'], 1)) * 100; ?>
                    <?php echo ($growth >= 0 ? '+' : ''); ?><?php echo number_format($growth, 1); ?>%
                </small>
            </div>
        </div>
    </div>
</div>

<?php endif; ?>
</div>

<?php include 'templates/footer.php'; ?>
