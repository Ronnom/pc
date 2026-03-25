<?php
/**
 * Dashboard / Home Page
 */

require_once 'includes/init.php';
requireLogin();

$pageTitle = 'Dashboard';
$db = getDB();

function dashboardTableExists($db, $tableName) {
    try {
        $row = $db->fetchOne(
            "SELECT 1 AS exists_flag
             FROM INFORMATION_SCHEMA.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
            [$tableName]
        );
        return !empty($row);
    } catch (Exception $e) {
        return false;
    }
}

function dashboardColumnExists($db, $tableName, $columnName) {
    try {
        $row = $db->fetchOne(
            "SELECT 1 AS exists_flag
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?",
            [$tableName, $columnName]
        );
        return !empty($row);
    } catch (Exception $e) {
        return false;
    }
}

function dashboardFetchMetric($db, $sql, $key, $default = 0) {
    try {
        $row = $db->fetchOne($sql);
        return isset($row[$key]) ? $row[$key] : $default;
    } catch (Exception $e) {
        error_log('Dashboard metric query failed: ' . $e->getMessage() . ' | SQL: ' . $sql);
        return $default;
    }
}

$productsHasIsActive = dashboardColumnExists($db, 'products', 'is_active');
$productsHasMinStock = dashboardColumnExists($db, 'products', 'min_stock_level');
$productsHasReorder = dashboardColumnExists($db, 'products', 'reorder_level');
$transactionsTableExists = dashboardTableExists($db, 'transactions');
$transactionsHasDate = dashboardColumnExists($db, 'transactions', 'transaction_date');
$transactionsHasStatus = dashboardColumnExists($db, 'transactions', 'status');
$transactionsHasTotal = dashboardColumnExists($db, 'transactions', 'total_amount');

$activeProductFilter = $productsHasIsActive ? " AND is_active = 1" : "";
$lowStockCondition = "stock_quantity <= 5";
if ($productsHasMinStock) {
    $lowStockCondition = "stock_quantity <= min_stock_level";
} elseif ($productsHasReorder) {
    $lowStockCondition = "stock_quantity <= reorder_level";
}

// Get statistics
$stats = [
    'total_products' => dashboardFetchMetric(
        $db,
        "SELECT COUNT(*) as count FROM products WHERE 1=1{$activeProductFilter}",
        'count',
        0
    ),
    'low_stock' => dashboardFetchMetric(
        $db,
        "SELECT COUNT(*) as count FROM products WHERE {$lowStockCondition}{$activeProductFilter}",
        'count',
        0
    ),
    'total_sales_today' => ($transactionsTableExists && $transactionsHasDate && $transactionsHasTotal)
        ? dashboardFetchMetric(
            $db,
            "SELECT COALESCE(SUM(total_amount), 0) as total
             FROM transactions
             WHERE DATE(transaction_date) = CURDATE()" . ($transactionsHasStatus ? " AND status = 'completed'" : ""),
            'total',
            0
        )
        : 0,
    'total_transactions_today' => ($transactionsTableExists && $transactionsHasDate)
        ? dashboardFetchMetric(
            $db,
            "SELECT COUNT(*) as count FROM transactions WHERE DATE(transaction_date) = CURDATE()",
            'count',
            0
        )
        : 0,
];

// Recent transactions
$recentTransactions = [];
if ($transactionsTableExists) {
    try {
        $recentTransactions = $db->fetchAll(
            "SELECT t.*, c.first_name, c.last_name, u.username 
             FROM transactions t 
             LEFT JOIN customers c ON t.customer_id = c.id 
             LEFT JOIN users u ON t.user_id = u.id 
             ORDER BY t.created_at DESC 
             LIMIT 10"
        );
    } catch (Exception $e) {
        error_log('Recent transactions query failed: ' . $e->getMessage());
    }
}

// Low stock products
$lowStockProducts = [];
try {
    $thresholdSelect = $productsHasMinStock ? "min_stock_level AS threshold" : ($productsHasReorder ? "reorder_level AS threshold" : "5 AS threshold");
    $lowStockProducts = $db->fetchAll(
        "SELECT id, sku, name, stock_quantity, {$thresholdSelect}
         FROM products
         WHERE {$lowStockCondition}{$activeProductFilter}
         ORDER BY stock_quantity ASC
         LIMIT 10"
    );
} catch (Exception $e) {
    error_log('Low stock products query failed: ' . $e->getMessage());
}

include 'templates/header.php';
?>

<div class="row mb-4">
    <div class="col-12">
        <h1 class="h3 mb-0">Dashboard</h1>
        <?php
            $currentUser = getCurrentUser();
            $welcomeName = 'User';
            if (!empty($currentUser['first_name'])) {
                $welcomeName = $currentUser['first_name'];
            } elseif (!empty($currentUser['full_name'])) {
                $welcomeName = $currentUser['full_name'];
            } elseif (!empty($currentUser['username'])) {
                $welcomeName = $currentUser['username'];
            }
        ?>
        <p class="text-muted">Welcome back, <?php echo escape($welcomeName); ?>!</p>
    </div>
</div>

<!-- Statistics Cards -->
<div class="row mb-4">
    <div class="col-md-3 mb-3">
        <div class="card stat-card border-primary">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="text-muted mb-1">Total Products</h6>
                        <h3 class="mb-0"><?php echo number_format($stats['total_products']); ?></h3>
                    </div>
                    <div class="stat-icon text-primary">
                        <i class="bi bi-box-seam"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-md-3 mb-3">
        <div class="card stat-card border-warning">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="text-muted mb-1">Low Stock Items</h6>
                        <h3 class="mb-0"><?php echo number_format($stats['low_stock']); ?></h3>
                    </div>
                    <div class="stat-icon text-warning">
                        <i class="bi bi-exclamation-triangle"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-md-3 mb-3">
        <div class="card stat-card border-success">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="text-muted mb-1">Sales Today</h6>
                        <h3 class="mb-0"><?php echo formatCurrency($stats['total_sales_today']); ?></h3>
                    </div>
                    <div class="stat-icon text-success">
                        <i class="bi bi-cash-stack"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-md-3 mb-3">
        <div class="card stat-card border-info">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="text-muted mb-1">Transactions Today</h6>
                        <h3 class="mb-0"><?php echo number_format($stats['total_transactions_today']); ?></h3>
                    </div>
                    <div class="stat-icon text-info">
                        <i class="bi bi-receipt"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <!-- Recent Transactions -->
    <div class="col-lg-8 mb-4">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="bi bi-clock-history"></i> Recent Transactions</h5>
                <a href="<?php echo getBaseUrl(); ?>/transactions.php" class="btn btn-sm btn-outline-primary">View All</a>
            </div>
            <div class="card-body">
                <?php if (empty($recentTransactions)): ?>
                    <p class="text-muted text-center mb-0">No recent transactions</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Transaction #</th>
                                    <th>Customer</th>
                                    <th>Date</th>
                                    <th>Amount</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recentTransactions as $transaction): ?>
                                <tr>
                                    <td><a href="<?php echo getBaseUrl(); ?>/transactions.php?view=<?php echo $transaction['id']; ?>"><?php echo escape($transaction['transaction_number']); ?></a></td>
                                    <td><?php echo $transaction['customer_id'] ? escape($transaction['first_name'] . ' ' . $transaction['last_name']) : 'Walk-in'; ?></td>
                                    <td><?php echo formatDateTime($transaction['transaction_date']); ?></td>
                                    <td><?php echo formatCurrency($transaction['total_amount']); ?></td>
                                    <td><?php echo getStatusBadge($transaction['status']); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Low Stock Alert -->
    <div class="col-lg-4 mb-4">
        <div class="card border-warning">
            <div class="card-header bg-warning text-dark">
                <h5 class="mb-0"><i class="bi bi-exclamation-triangle"></i> Low Stock Alert</h5>
            </div>
            <div class="card-body">
                <?php if (empty($lowStockProducts)): ?>
                    <p class="text-muted text-center mb-0">All products are well stocked</p>
                <?php else: ?>
                    <div class="list-group list-group-flush">
                        <?php foreach ($lowStockProducts as $product): ?>
                        <a href="<?php echo getBaseUrl(); ?>/products.php?view=<?php echo $product['id']; ?>" class="list-group-item list-group-item-action">
                            <div class="d-flex w-100 justify-content-between">
                                <h6 class="mb-1"><?php echo escape($product['name']); ?></h6>
                                <small class="text-danger"><?php echo $product['stock_quantity']; ?> left</small>
                            </div>
                            <small class="text-muted">SKU: <?php echo escape($product['sku']); ?></small>
                        </a>
                        <?php endforeach; ?>
                    </div>
                    <div class="mt-3">
                        <a href="<?php echo getBaseUrl(); ?>/inventory.php?filter=low_stock" class="btn btn-sm btn-warning w-100">View All Low Stock</a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php include 'templates/footer.php'; ?>

