<?php
/**
 * Customer Reports & Analytics
 * Customer analysis, segmentation, top/inactive customers, demographics
 */

require_once 'includes/init.php';
requireLogin();
requirePermission('reports.view');

$db = getDB();
$report_type = trim($_GET['type'] ?? 'analysis');
$date_from = trim($_GET['from'] ?? date('Y-m-01'));
$date_to = trim($_GET['to'] ?? date('Y-m-d'));
$inactivity_days = (int)($_GET['days'] ?? 90);

// CUSTOMER ANALYSIS
if ($report_type === 'analysis') {
    $total_customers = $db->fetch(
        "SELECT COUNT(*) as count FROM customers WHERE is_active = 1"
    );
    
    $new_customers = $db->fetch(
        "SELECT COUNT(*) as count FROM customers 
         WHERE is_active = 1 AND DATE(created_at) BETWEEN ? AND ?",
        [$date_from, $date_to]
    );
    
    $active_customers = $db->fetch(
        "SELECT COUNT(DISTINCT customer_id) as count FROM transactions 
         WHERE status = 'completed' AND DATE(transaction_date) BETWEEN ? AND ?",
        [$date_from, $date_to]
    );
    
    $repeat_customers = $db->fetch(
        "SELECT COUNT(DISTINCT customer_id) as count FROM (
            SELECT customer_id FROM transactions WHERE status = 'completed' GROUP BY customer_id HAVING COUNT(*) > 1
         ) t"
    );
    
    $avg_lifetime_value = $db->fetch(
        "SELECT COALESCE(AVG(lifetime_spent), 0) as avg_ltv FROM (
            SELECT customer_id, COALESCE(SUM(total_amount), 0) as lifetime_spent 
            FROM transactions WHERE status = 'completed' 
            GROUP BY customer_id
         ) ltv"
    );
    
    $avg_transaction_value = $db->fetch(
        "SELECT COALESCE(AVG(total_amount), 0) as avg_value FROM transactions 
         WHERE status = 'completed' AND DATE(transaction_date) BETWEEN ? AND ?",
        [$date_from, $date_to]
    );
}

// TOP CUSTOMERS
elseif ($report_type === 'top_customers') {
    $top_by_spending = $db->fetchAll(
        "SELECT c.id, c.first_name, c.last_name, c.email,
                COUNT(DISTINCT t.id) as transaction_count,
                COALESCE(SUM(t.total_amount), 0) as total_spent,
                COALESCE(AVG(t.total_amount), 0) as avg_transaction,
                MAX(t.transaction_date) as last_purchase
         FROM customers c
         LEFT JOIN transactions t ON c.id = t.customer_id AND t.status = 'completed'
         WHERE c.is_active = 1
         GROUP BY c.id, c.first_name, c.last_name, c.email
         ORDER BY total_spent DESC
         LIMIT 20"
    );
    
    $top_by_frequency = $db->fetchAll(
        "SELECT c.id, c.first_name, c.last_name,
                COUNT(DISTINCT t.id) as transaction_count,
                COALESCE(SUM(t.total_amount), 0) as total_spent
         FROM customers c
         LEFT JOIN transactions t ON c.id = t.customer_id AND t.status = 'completed'
         WHERE c.is_active = 1
         GROUP BY c.id, c.first_name, c.last_name
         ORDER BY transaction_count DESC
         LIMIT 10"
    );
}

// INACTIVE CUSTOMERS
elseif ($report_type === 'inactive') {
    $inactive = $db->fetchAll(
        "SELECT c.id, c.first_name, c.last_name, c.email, c.phone,
                COALESCE(COUNT(DISTINCT t.id), 0) as total_purchases,
                COALESCE(SUM(t.total_amount), 0) as lifetime_spent,
                MAX(t.transaction_date) as last_purchase,
                DATEDIFF(NOW(), MAX(t.transaction_date)) as days_inactive
         FROM customers c
         LEFT JOIN transactions t ON c.id = t.customer_id AND t.status = 'completed'
         WHERE c.is_active = 1
         GROUP BY c.id, c.first_name, c.last_name, c.email, c.phone
         HAVING MAX(t.transaction_date) IS NULL OR DATEDIFF(NOW(), MAX(t.transaction_date)) >= ?
         ORDER BY days_inactive DESC",
        [$inactivity_days]
    );
}

// CUSTOMER DEMOGRAPHICS
elseif ($report_type === 'demographics') {
    $by_city = $db->fetchAll(
        "SELECT city, COUNT(*) as count FROM customers WHERE city IS NOT NULL AND city != '' AND is_active = 1 GROUP BY city ORDER BY count DESC"
    );
    
    $by_type = $db->fetchAll(
        "SELECT customer_type, COUNT(*) as count FROM customers WHERE is_active = 1 GROUP BY customer_type ORDER BY count DESC"
    );
    
    $by_loyalty = $db->fetchAll(
        "SELECT loyalty_tier, COUNT(*) as count FROM customers WHERE is_active = 1 GROUP BY loyalty_tier ORDER BY count DESC"
    );
}

$pageTitle = 'Customer Reports';
include 'templates/header.php';
?>

<div class="row mb-4">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center">
            <h1 class="h3 mb-0">Customer Reports & Analytics</h1>
            <a href="<?php echo getBaseUrl(); ?>/reports.php" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left"></i> Back
            </a>
        </div>
    </div>
</div>

<!-- REPORT TABS -->
<ul class="nav nav-tabs mb-4">
    <li class="nav-item"><a class="nav-link <?php echo $report_type === 'analysis' ? 'active' : ''; ?>" href="?type=analysis">Analysis</a></li>
    <li class="nav-item"><a class="nav-link <?php echo $report_type === 'top_customers' ? 'active' : ''; ?>" href="?type=top_customers">Top Customers</a></li>
    <li class="nav-item"><a class="nav-link <?php echo $report_type === 'inactive' ? 'active' : ''; ?>" href="?type=inactive">Inactive</a></li>
    <li class="nav-item"><a class="nav-link <?php echo $report_type === 'demographics' ? 'active' : ''; ?>" href="?type=demographics">Demographics</a></li>
</ul>

<!-- CUSTOMER ANALYSIS -->
<?php if ($report_type === 'analysis'): ?>
<div class="row mb-4">
    <div class="col-md-3">
        <div class="card border-primary">
            <div class="card-body">
                <div class="text-muted small mb-2">Total Customers</div>
                <div class="display-6 text-primary"><?php echo (int)($total_customers['count'] ?? 0); ?></div>
                <small class="text-muted">Active only</small>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-success">
            <div class="card-body">
                <div class="text-muted small mb-2">New Customers</div>
                <div class="display-6 text-success"><?php echo (int)($new_customers['count'] ?? 0); ?></div>
                <small class="text-muted">This period</small>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-info">
            <div class="card-body">
                <div class="text-muted small mb-2">Repeat Customers</div>
                <div class="display-6 text-info"><?php echo (int)($repeat_customers['count'] ?? 0); ?></div>
                <small class="text-muted">Purchased 2+ times</small>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-warning">
            <div class="card-body">
                <div class="text-muted small mb-2">Avg Lifetime Value</div>
                <div class="display-6 text-warning"><?php echo formatCurrency($avg_lifetime_value['avg_ltv'] ?? 0); ?></div>
                <small class="text-muted">Per customer</small>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-md-6">
        <div class="card">
            <div class="card-body">
                <div class="text-muted small mb-2">Active Customers (Period)</div>
                <div class="display-5 text-info"><?php echo (int)($active_customers['count'] ?? 0); ?></div>
                <small>Made at least one purchase</small>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card">
            <div class="card-body">
                <div class="text-muted small mb-2">Avg Transaction Value</div>
                <div class="display-5 text-success"><?php echo formatCurrency($avg_transaction_value['avg_value'] ?? 0); ?></div>
                <small>Per transaction</small>
            </div>
        </div>
    </div>
</div>

<?php endif; ?>

<!-- TOP CUSTOMERS -->
<?php if ($report_type === 'top_customers'): ?>
<div class="row mb-4">
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">Top 20 by Spending</div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>Customer</th>
                                <th class="text-end">Count</th>
                                <th class="text-end">Spent</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($top_by_spending as $cust): ?>
                            <tr>
                                <td>
                                    <a href="<?php echo getBaseUrl(); ?>/customer_profile.php?id=<?php echo $cust['id']; ?>">
                                        <?php echo escape($cust['first_name'] . ' ' . $cust['last_name']); ?>
                                    </a>
                                </td>
                                <td class="text-end"><?php echo (int)$cust['transaction_count']; ?></td>
                                <td class="text-end font-weight-bold"><?php echo formatCurrency($cust['total_spent']); ?></td>
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
            <div class="card-header">Top 10 by Frequency</div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>Customer</th>
                                <th class="text-end">Purchases</th>
                                <th class="text-end">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($top_by_frequency as $cust): ?>
                            <tr>
                                <td><?php echo escape($cust['first_name'] . ' ' . $cust['last_name']); ?></td>
                                <td class="text-end"><?php echo (int)$cust['transaction_count']; ?></td>
                                <td class="text-end"><?php echo formatCurrency($cust['total_spent']); ?></td>
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

<!-- INACTIVE CUSTOMERS -->
<?php if ($report_type === 'inactive'): ?>
<div class="alert alert-info mb-4">
    Showing customers with no purchases in the last <strong><?php echo $inactivity_days; ?> days</strong>
</div>

<div class="card">
    <div class="card-header">Inactive Customers</div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-sm">
                <thead>
                    <tr>
                        <th>Customer</th>
                        <th>Email</th>
                        <th class="text-end">Purchases</th>
                        <th class="text-end">Lifetime Spent</th>
                        <th class="text-end">Last Purchase</th>
                        <th class="text-end">Days Inactive</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($inactive as $cust): ?>
                    <tr>
                        <td><?php echo escape($cust['first_name'] . ' ' . $cust['last_name']); ?></td>
                        <td><small><?php echo escape($cust['email']); ?></small></td>
                        <td class="text-end"><?php echo (int)$cust['total_purchases']; ?></td>
                        <td class="text-end"><?php echo formatCurrency($cust['lifetime_spent']); ?></td>
                        <td class="text-end"><?php echo $cust['last_purchase'] ? formatDate($cust['last_purchase']) : 'Never'; ?></td>
                        <td class="text-end"><?php echo (int)($cust['days_inactive'] ?? 0); ?> days</td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php endif; ?>

<!-- DEMOGRAPHICS -->
<?php if ($report_type === 'demographics'): ?>
<div class="row mb-4">
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">By Type</div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead>
                            <tr><th>Type</th><th class="text-end">Count</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($by_type as $type): ?>
                            <tr>
                                <td><?php echo ucfirst($type['customer_type']); ?></td>
                                <td class="text-end"><?php echo (int)$type['count']; ?></td>
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
            <div class="card-header">By Loyalty Tier</div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead>
                            <tr><th>Tier</th><th class="text-end">Count</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($by_loyalty as $tier): ?>
                            <tr>
                                <td><?php echo escape($tier['loyalty_tier']); ?></td>
                                <td class="text-end"><?php echo (int)$tier['count']; ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">Top 15 Cities</div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-sm">
                <thead>
                    <tr><th>City</th><th class="text-end">Customers</th></tr>
                </thead>
                <tbody>
                    <?php foreach (array_slice($by_city, 0, 15) as $city): ?>
                    <tr>
                        <td><?php echo escape($city['city']); ?></td>
                        <td class="text-end"><?php echo (int)$city['count']; ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php endif; ?>

<?php include 'templates/footer.php'; ?>
