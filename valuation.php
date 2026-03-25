<?php
/**
 * Stock Valuation Page
 * Inventory valuation with FIFO/LIFO/Average costing
 */

require_once 'includes/init.php';
requireLogin();
requirePermission('inventory.view');

$pageTitle = 'Stock Valuation';
$stockModule = new StockManagementModule();
$productsModule = new ProductsModule();
$db = getDB();

$costingMethod = $_GET['method'] ?? 'Average';
$agingDays = (int)($_GET['aging'] ?? 90);

// Get inventory valuation
$totalValue = $stockModule->calculateInventoryValue($costingMethod);
$totalInventoryValue = $totalValue[0]['total_value'] ?? 0;

// Get products with valuation details
$products = $db->fetchAll(
    "SELECT p.*, 
            (p.stock_quantity * p.cost_price) as inventory_value,
            DATEDIFF(NOW(), p.updated_at) as days_since_update,
            (SELECT COUNT(*) FROM transaction_items ti 
             INNER JOIN transactions t ON ti.transaction_id = t.id 
             WHERE ti.product_id = p.id AND t.status = 'completed' 
             AND t.transaction_date >= DATE_SUB(NOW(), INTERVAL 90 DAY)) as sales_last_90_days
     FROM products p 
     WHERE p.is_active = 1 AND p.deleted_at IS NULL AND p.stock_quantity > 0
     ORDER BY (p.stock_quantity * p.cost_price) DESC"
);

// Calculate aging
$aging30 = 0;
$aging60 = 0;
$aging90 = 0;
$aging90Plus = 0;
$deadStock = [];

foreach ($products as $product) {
    $days = $product['days_since_update'];
    $value = $product['inventory_value'];
    
    if ($days <= 30) {
        $aging30 += $value;
    } elseif ($days <= 60) {
        $aging60 += $value;
    } elseif ($days <= 90) {
        $aging90 += $value;
    } else {
        $aging90Plus += $value;
    }
    
    // Dead stock (no sales in 90 days and no movement)
    if ($product['sales_last_90_days'] == 0 && $days > 90) {
        $deadStock[] = $product;
    }
}

// Calculate turnover ratio (simplified)
$totalSales = $db->fetchOne(
    "SELECT SUM(ti.total) as total 
     FROM transaction_items ti 
     INNER JOIN transactions t ON ti.transaction_id = t.id 
     WHERE t.status = 'completed' 
     AND t.transaction_date >= DATE_SUB(NOW(), INTERVAL 365 DAY)"
);
$avgInventory = $totalInventoryValue / 2; // Simplified
$turnoverRatio = $avgInventory > 0 ? ($totalSales['total'] / $avgInventory) : 0;

include 'templates/header.php';
?>

<div class="row mb-4">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center">
            <h1 class="h3 mb-0">Stock Valuation</h1>
            <div class="btn-group" role="group">
                <a href="?method=FIFO" class="btn btn-outline-primary <?php echo $costingMethod === 'FIFO' ? 'active' : ''; ?>">FIFO</a>
                <a href="?method=LIFO" class="btn btn-outline-primary <?php echo $costingMethod === 'LIFO' ? 'active' : ''; ?>">LIFO</a>
                <a href="?method=Average" class="btn btn-outline-primary <?php echo $costingMethod === 'Average' ? 'active' : ''; ?>">Average</a>
            </div>
        </div>
    </div>
</div>

<!-- Summary Cards -->
<div class="row mb-4">
    <div class="col-md-3">
        <div class="card border-primary">
            <div class="card-body text-center">
                <h6 class="text-muted mb-2">Total Inventory Value</h6>
                <h3 class="mb-0 text-primary"><?php echo formatCurrency($totalInventoryValue); ?></h3>
                <small class="text-muted"><?php echo $costingMethod; ?> Method</small>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-success">
            <div class="card-body text-center">
                <h6 class="text-muted mb-2">Aging 0-30 Days</h6>
                <h3 class="mb-0 text-success"><?php echo formatCurrency($aging30); ?></h3>
                <small class="text-muted"><?php echo number_format(($aging30 / $totalInventoryValue) * 100, 1); ?>%</small>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-warning">
            <div class="card-body text-center">
                <h6 class="text-muted mb-2">Aging 60-90 Days</h6>
                <h3 class="mb-0 text-warning"><?php echo formatCurrency($aging60 + $aging90); ?></h3>
                <small class="text-muted"><?php echo number_format((($aging60 + $aging90) / $totalInventoryValue) * 100, 1); ?>%</small>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-danger">
            <div class="card-body text-center">
                <h6 class="text-muted mb-2">Aging 90+ Days</h6>
                <h3 class="mb-0 text-danger"><?php echo formatCurrency($aging90Plus); ?></h3>
                <small class="text-muted"><?php echo number_format(($aging90Plus / $totalInventoryValue) * 100, 1); ?>%</small>
            </div>
        </div>
    </div>
</div>

<!-- Inventory Aging Report -->
<div class="row mb-4">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">Inventory Aging Report</h5>
            </div>
            <div class="card-body">
                <canvas id="agingChart" height="80"></canvas>
            </div>
        </div>
    </div>
</div>

<!-- Dead Stock -->
<?php if (!empty($deadStock)): ?>
<div class="row mb-4">
    <div class="col-12">
        <div class="card border-danger">
            <div class="card-header bg-danger text-white">
                <h5 class="mb-0"><i class="bi bi-exclamation-triangle"></i> Dead Stock (No movement in 90+ days)</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th>Stock Qty</th>
                                <th>Value</th>
                                <th>Days Since Update</th>
                                <th>Last 90 Days Sales</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($deadStock as $product): ?>
                            <tr>
                                <td><?php echo escape($product['name']); ?> (<?php echo escape($product['sku']); ?>)</td>
                                <td><?php echo $product['stock_quantity']; ?></td>
                                <td><?php echo formatCurrency($product['inventory_value']); ?></td>
                                <td><?php echo $product['days_since_update']; ?> days</td>
                                <td><?php echo $product['sales_last_90_days']; ?></td>
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

<!-- Top Products by Value -->
<div class="row">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">Top Products by Inventory Value</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th>Stock Qty</th>
                                <th>Cost Price</th>
                                <th>Total Value</th>
                                <th>Aging</th>
                                <th>Turnover</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach (array_slice($products, 0, 20) as $product): ?>
                            <tr>
                                <td><?php echo escape($product['name']); ?> (<?php echo escape($product['sku']); ?>)</td>
                                <td><?php echo $product['stock_quantity']; ?></td>
                                <td><?php echo formatCurrency($product['cost_price']); ?></td>
                                <td><strong><?php echo formatCurrency($product['inventory_value']); ?></strong></td>
                                <td>
                                    <?php
                                    $days = $product['days_since_update'];
                                    $badge = $days <= 30 ? 'success' : ($days <= 90 ? 'warning' : 'danger');
                                    ?>
                                    <span class="badge bg-<?php echo $badge; ?>"><?php echo $days; ?> days</span>
                                </td>
                                <td><?php echo $product['sales_last_90_days']; ?> sales</td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
// Inventory Aging Chart
const ctx = document.getElementById('agingChart').getContext('2d');
new Chart(ctx, {
    type: 'bar',
    data: {
        labels: ['0-30 Days', '31-60 Days', '61-90 Days', '90+ Days'],
        datasets: [{
            label: 'Inventory Value',
            data: [
                <?php echo $aging30; ?>,
                <?php echo $aging60; ?>,
                <?php echo $aging90; ?>,
                <?php echo $aging90Plus; ?>
            ],
            backgroundColor: [
                'rgba(40, 167, 69, 0.8)',
                'rgba(255, 193, 7, 0.8)',
                'rgba(255, 152, 0, 0.8)',
                'rgba(220, 53, 69, 0.8)'
            ]
        }]
    },
    options: {
        responsive: true,
        scales: {
            y: {
                beginAtZero: true,
                ticks: {
                    callback: function(value) {
                        return '$' + value.toLocaleString();
                    }
                }
            }
        },
        plugins: {
            tooltip: {
                callbacks: {
                    label: function(context) {
                        return 'Value: $' + context.parsed.y.toLocaleString();
                    }
                }
            }
        }
    }
});
</script>

<?php include 'templates/footer.php'; ?>

