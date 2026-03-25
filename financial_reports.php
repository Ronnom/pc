<?php
/**
 * Financial Reports
 * Revenue, profit, expenses, and tax reporting
 */

require_once 'includes/init.php';
requireLogin();
requirePermission('reports.view');

$db = getDB();
$report_type = trim($_GET['type'] ?? 'revenue');
$date_from = trim($_GET['from'] ?? date('Y-m-01'));
$date_to = trim($_GET['to'] ?? date('Y-m-d'));

// REVENUE REPORT
if ($report_type === 'revenue') {
    $daily_revenue = $db->fetchAll(
        "SELECT DATE(transaction_date) as date,
                COUNT(*) as transaction_count,
                COALESCE(SUM(total_amount), 0) as total_revenue,
                COALESCE(SUM(tax_amount), 0) as tax_collected,
                COALESCE(SUM(CASE WHEN status = 'completed' THEN total_amount ELSE 0 END), 0) as completed_sales
         FROM transactions
         WHERE DATE(transaction_date) BETWEEN ? AND ?
         GROUP BY DATE(transaction_date)
         ORDER BY date DESC",
        [$date_from, $date_to]
    );
    
    $revenue_summary = $db->fetch(
        "SELECT COUNT(*) as total_transactions,
                COALESCE(SUM(total_amount), 0) as total_revenue,
                COALESCE(AVG(total_amount), 0) as avg_transaction,
                COALESCE(SUM(tax_amount), 0) as tax_collected
         FROM transactions
         WHERE status = 'completed' AND DATE(transaction_date) BETWEEN ? AND ?",
        [$date_from, $date_to]
    );
}

// PROFIT REPORT
elseif ($report_type === 'profit') {
    // By Product
    $profit_by_product = $db->fetchAll(
        "SELECT p.id, p.name,
                COALESCE(SUM(ti.quantity), 0) as qty_sold,
                COALESCE(SUM(ti.quantity * ti.unit_price), 0) as revenue,
                COALESCE(SUM(ti.quantity * COALESCE(ti.serial_cost_price, p.cost_price)), 0) as cost,
                COALESCE(SUM(ti.quantity * (ti.unit_price - COALESCE(ti.serial_cost_price, p.cost_price))), 0) as gross_profit,
                COALESCE(ROUND(100.0 * SUM(ti.quantity * (ti.unit_price - COALESCE(ti.serial_cost_price, p.cost_price))) / NULLIF(SUM(ti.quantity * ti.unit_price), 0), 2), 0) as margin_pct
         FROM transaction_items ti
         JOIN products p ON ti.product_id = p.id
         JOIN transactions t ON ti.transaction_id = t.id
         WHERE t.status = 'completed' AND DATE(t.transaction_date) BETWEEN ? AND ?
         GROUP BY p.id, p.name
         ORDER BY gross_profit DESC",
        [$date_from, $date_to]
    );
    
    // By Category
    $profit_by_category = $db->fetchAll(
        "SELECT c.id, c.name,
                COALESCE(SUM(ti.quantity * ti.unit_price), 0) as revenue,
                COALESCE(SUM(ti.quantity * COALESCE(ti.serial_cost_price, p.cost_price)), 0) as cost,
                COALESCE(SUM(ti.quantity * (ti.unit_price - COALESCE(ti.serial_cost_price, p.cost_price))), 0) as gross_profit,
                COALESCE(ROUND(100.0 * SUM(ti.quantity * (ti.unit_price - COALESCE(ti.serial_cost_price, p.cost_price))) / NULLIF(SUM(ti.quantity * ti.unit_price), 0), 2), 0) as margin_pct
         FROM transaction_items ti
         JOIN products p ON ti.product_id = p.id
         JOIN categories c ON p.category_id = c.id
         JOIN transactions t ON ti.transaction_id = t.id
         WHERE t.status = 'completed' AND DATE(t.transaction_date) BETWEEN ? AND ?
         GROUP BY c.id, c.name
         ORDER BY gross_profit DESC",
        [$date_from, $date_to]
    );
    
    // Overall profit
    $profit_summary = $db->fetch(
        "SELECT COALESCE(SUM(ti.quantity * ti.unit_price), 0) as total_revenue,
                COALESCE(SUM(ti.quantity * COALESCE(ti.serial_cost_price, p.cost_price)), 0) as total_cost,
                COALESCE(SUM(ti.quantity * (ti.unit_price - COALESCE(ti.serial_cost_price, p.cost_price))), 0) as gross_profit
         FROM transaction_items ti
         JOIN products p ON ti.product_id = p.id
         JOIN transactions t ON ti.transaction_id = t.id
         WHERE t.status = 'completed' AND DATE(t.transaction_date) BETWEEN ? AND ?",
        [$date_from, $date_to]
    );
}

// EXPENSE TRACKING
elseif ($report_type === 'expenses') {
    $expenses_by_category = $db->fetchAll(
        "SELECT category, 
                COUNT(*) as count,
                COALESCE(SUM(amount), 0) as total
         FROM expenses
         WHERE expense_date BETWEEN ? AND ?
         GROUP BY category
         ORDER BY total DESC",
        [$date_from, $date_to]
    );
    
    $all_expenses = $db->fetchAll(
        "SELECT id, description, category, amount, expense_date
         FROM expenses
         WHERE expense_date BETWEEN ? AND ?
         ORDER BY expense_date DESC",
        [$date_from, $date_to]
    );
    
    $expense_summary = $db->fetch(
        "SELECT COUNT(*) as transaction_count, COALESCE(SUM(amount), 0) as total
         FROM expenses
         WHERE expense_date BETWEEN ? AND ?",
        [$date_from, $date_to]
    );
}

// TAX REPORT
elseif ($report_type === 'tax') {
    $tax_by_date = $db->fetchAll(
        "SELECT DATE(transaction_date) as date,
                COUNT(*) as num_transactions,
                COALESCE(SUM(total_amount), 0) as taxable_amount,
                COALESCE(SUM(tax_amount), 0) as tax_collected
         FROM transactions
         WHERE status = 'completed' AND DATE(transaction_date) BETWEEN ? AND ?
         GROUP BY DATE(transaction_date)
         ORDER BY date DESC",
        [$date_from, $date_to]
    );
    
    $tax_summary = $db->fetch(
        "SELECT COALESCE(SUM(total_amount), 0) as taxable_amount,
                COALESCE(SUM(tax_amount), 0) as total_tax_collected,
                COALESCE(COUNT(*), 0) as num_transactions,
                COALESCE(AVG(CASE WHEN tax_amount > 0 THEN (tax_amount / NULLIF(total_amount - tax_amount, 0)) * 100 END), 0) as avg_tax_rate
         FROM transactions
         WHERE status = 'completed' AND DATE(transaction_date) BETWEEN ? AND ?",
        [$date_from, $date_to]
    );
    
    // Tax by payment method
    $tax_by_method = $db->fetchAll(
        "SELECT p.payment_method,
                COUNT(DISTINCT t.id) as num_transactions,
                COALESCE(SUM(t.tax_amount), 0) as tax_collected
         FROM transactions t
         JOIN payments p ON t.id = p.transaction_id
         WHERE t.status = 'completed' AND DATE(t.transaction_date) BETWEEN ? AND ?
         GROUP BY p.payment_method
         ORDER BY tax_collected DESC",
        [$date_from, $date_to]
    );
}

$pageTitle = 'Financial Reports';
include 'templates/header.php';
?>

<div class="row mb-4">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center">
            <h1 class="h3 mb-0">Financial Reports</h1>
            <a href="<?php echo getBaseUrl(); ?>/reports.php" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left"></i> Back
            </a>
        </div>
    </div>
</div>

<!-- REPORT TABS -->
<ul class="nav nav-tabs mb-4">
    <li class="nav-item"><a class="nav-link <?php echo $report_type === 'revenue' ? 'active' : ''; ?>" href="?type=revenue&from=<?php echo $date_from; ?>&to=<?php echo $date_to; ?>">Revenue</a></li>
    <li class="nav-item"><a class="nav-link <?php echo $report_type === 'profit' ? 'active' : ''; ?>" href="?type=profit&from=<?php echo $date_from; ?>&to=<?php echo $date_to; ?>">Profit</a></li>
    <li class="nav-item"><a class="nav-link <?php echo $report_type === 'expenses' ? 'active' : ''; ?>" href="?type=expenses&from=<?php echo $date_from; ?>&to=<?php echo $date_to; ?>">Expenses</a></li>
    <li class="nav-item"><a class="nav-link <?php echo $report_type === 'tax' ? 'active' : ''; ?>" href="?type=tax&from=<?php echo $date_from; ?>&to=<?php echo $date_to; ?>">Tax</a></li>
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
            <div class="col-md-6 d-flex align-items-end">
                <button type="submit" class="btn btn-primary">Filter</button>
            </div>
        </form>
    </div>
</div>

<!-- REVENUE REPORT -->
<?php if ($report_type === 'revenue'): ?>
<div class="row mb-4">
    <div class="col-md-4">
        <div class="card border-success">
            <div class="card-body">
                <div class="text-muted small mb-2">Total Revenue</div>
                <div class="display-6 text-success"><?php echo formatCurrency($revenue_summary['total_revenue']); ?></div>
                <small><?php echo (int)$revenue_summary['total_transactions']; ?> transactions</small>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card border-info">
            <div class="card-body">
                <div class="text-muted small mb-2">Avg per Transaction</div>
                <div class="display-6 text-info"><?php echo formatCurrency($revenue_summary['avg_transaction']); ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card border-primary">
            <div class="card-body">
                <div class="text-muted small mb-2">Tax Collected</div>
                <div class="display-6 text-primary"><?php echo formatCurrency($revenue_summary['tax_collected']); ?></div>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">Daily Revenue</div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-sm">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th class="text-end">Transactions</th>
                        <th class="text-end">Revenue</th>
                        <th class="text-end">Tax</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($daily_revenue as $day): ?>
                    <tr>
                        <td><?php echo formatDate($day['date']); ?></td>
                        <td class="text-end"><?php echo (int)$day['transaction_count']; ?></td>
                        <td class="text-end"><?php echo formatCurrency($day['total_revenue']); ?></td>
                        <td class="text-end"><?php echo formatCurrency($day['tax_collected']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php endif; ?>

<!-- PROFIT REPORT -->
<?php if ($report_type === 'profit'): ?>
<div class="row mb-4">
    <div class="col-md-4">
        <div class="card border-success">
            <div class="card-body">
                <div class="text-muted small mb-2">Gross Profit</div>
                <div class="display-6 text-success"><?php echo formatCurrency($profit_summary['gross_profit']); ?></div>
                <small class="text-muted">Revenue - COGS</small>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card border-primary">
            <div class="card-body">
                <div class="text-muted small mb-2">Total Revenue</div>
                <div class="display-6 text-primary"><?php echo formatCurrency($profit_summary['total_revenue']); ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card border-danger">
            <div class="card-body">
                <div class="text-muted small mb-2">COGS</div>
                <div class="display-6 text-danger"><?php echo formatCurrency($profit_summary['total_cost']); ?></div>
                <small><?php $margin = ($profit_summary['total_revenue'] > 0) ? (($profit_summary['gross_profit'] / $profit_summary['total_revenue']) * 100) : 0; ?>
                Margin: <?php echo number_format($margin, 1); ?>%</small>
            </div>
        </div>
    </div>
</div>

<div class="row mb-4">
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">Profit by Category</div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>Category</th>
                                <th class="text-end">Revenue</th>
                                <th class="text-end">Margin %</th>
                                <th class="text-end">Profit</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($profit_by_category as $cat): ?>
                            <tr>
                                <td><?php echo escape($cat['name']); ?></td>
                                <td class="text-end"><?php echo formatCurrency($cat['revenue']); ?></td>
                                <td class="text-end"><?php echo number_format($cat['margin_pct'], 1); ?>%</td>
                                <td class="text-end text-success"><?php echo formatCurrency($cat['gross_profit']); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">Top 10 Products by Profit</div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th class="text-end">Qty</th>
                                <th class="text-end">Profit</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach (array_slice($profit_by_product, 0, 10) as $prod): ?>
                            <tr>
                                <td><?php echo escape($prod['name']); ?></td>
                                <td class="text-end"><?php echo (int)$prod['qty_sold']; ?></td>
                                <td class="text-end text-success"><?php echo formatCurrency($prod['gross_profit']); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<?php endif; ?>

<!-- EXPENSE REPORT -->
<?php if ($report_type === 'expenses'): ?>
<div class="card mb-4">
    <div class="card-body">
        <div class="row">
            <div class="col-md-6">
                <div class="text-muted small">Total Expenses</div>
                <div class="display-5 text-danger"><?php echo formatCurrency($expense_summary['total']); ?></div>
            </div>
            <div class="col-md-6">
                <div class="text-muted small">Transaction Count</div>
                <div class="display-5"><?php echo (int)$expense_summary['transaction_count']; ?></div>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-md-4">
        <div class="card">
            <div class="card-header">By Category</div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead>
                            <tr><th>Category</th><th class="text-end">Total</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($expenses_by_category as $exp): ?>
                            <tr>
                                <td><?php echo escape($exp['category']); ?></td>
                                <td class="text-end"><?php echo formatCurrency($exp['total']); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-8">
        <div class="card">
            <div class="card-header">All Expenses</div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead>
                            <tr><th>Date</th><th>Category</th><th>Description</th><th class="text-end">Amount</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($all_expenses as $exp): ?>
                            <tr>
                                <td><?php echo formatDate($exp['expense_date']); ?></td>
                                <td><?php echo escape($exp['category']); ?></td>
                                <td><?php echo escape($exp['description']); ?></td>
                                <td class="text-end"><?php echo formatCurrency($exp['amount']); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<?php endif; ?>

<!-- TAX REPORT -->
<?php if ($report_type === 'tax'): ?>
<div class="row mb-4">
    <div class="col-md-6">
        <div class="card">
            <div class="card-body">
                <div class="text-muted small mb-2">Total Tax Collected</div>
                <div class="display-5 text-primary"><?php echo formatCurrency($tax_summary['total_tax_collected']); ?></div>
                <small class="text-muted">Avg Rate: <?php echo number_format($tax_summary['avg_tax_rate'], 2); ?>%</small>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card">
            <div class="card-body">
                <div class="text-muted small mb-2">Taxable Sales</div>
                <div class="display-5 text-info"><?php echo formatCurrency($tax_summary['taxable_amount']); ?></div>
                <small class="text-muted"><?php echo (int)$tax_summary['num_transactions']; ?> transactions</small>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">Daily Tax Collection</div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-sm">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th class="text-end">Transactions</th>
                        <th class="text-end">Taxable Amount</th>
                        <th class="text-end">Tax Collected</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($tax_by_date as $day): ?>
                    <tr>
                        <td><?php echo formatDate($day['date']); ?></td>
                        <td class="text-end"><?php echo (int)$day['num_transactions']; ?></td>
                        <td class="text-end"><?php echo formatCurrency($day['taxable_amount']); ?></td>
                        <td class="text-end text-success"><?php echo formatCurrency($day['tax_collected']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php endif; ?>

<?php include 'templates/footer.php'; ?>
