<?php
/**
 * Inventory Reports
 * Stock levels, valuation, movement, aging, and reorder analysis
 */

require_once 'includes/init.php';
requireLogin();
requirePermission('reports.view');

$db = getDB();
$report_type = trim($_GET['type'] ?? 'stock_level');
$date_from = trim($_GET['from'] ?? date('Y-m-01'));
$date_to = trim($_GET['to'] ?? date('Y-m-d'));
requirePermission('reports.view');

// STOCK LEVEL REPORT
if ($report_type === 'stock_level') {
    $stock_levels = $db->fetchAll(
        "SELECT p.id, p.name, c.name as category, 
                p.stock_quantity, p.reorder_level,
                p.unit_cost, (p.stock_quantity * p.unit_cost) as stock_value,
                CASE WHEN p.stock_quantity = 0 THEN 'Out of Stock'
                     WHEN p.stock_quantity <= p.reorder_level THEN 'Low Stock'
                     ELSE 'In Stock' END as status
         FROM products p
         LEFT JOIN categories c ON p.category_id = c.id
         WHERE p.is_active = 1
         ORDER BY p.stock_quantity ASC"
    );
}

// INVENTORY VALUATION
elseif ($report_type === 'valuation') {
    $valuation_by_category = $db->fetchAll(
        "SELECT c.id, c.name,
                COUNT(p.id) as product_count,
                COALESCE(SUM(p.stock_quantity), 0) as total_qty,
                COALESCE(SUM(p.stock_quantity * p.unit_cost), 0) as total_value
         FROM categories c
         LEFT JOIN products p ON c.id = p.category_id AND p.is_active = 1
         GROUP BY c.id, c.name
         ORDER BY total_value DESC"
    );
    
    // Dead stock (no movement in 90+ days)
    $dead_stock = $db->fetchAll(
        "SELECT p.id, p.name, c.name as category,
                p.stock_quantity, (p.stock_quantity * p.unit_cost) as stock_value,
                p.updated_at, DATEDIFF(NOW(), p.updated_at) as days_untouched
         FROM products p
         LEFT JOIN categories c ON p.category_id = c.id
         WHERE p.is_active = 1 AND DATEDIFF(NOW(), p.updated_at) >= 90
         ORDER BY days_untouched DESC"
    );
}

// STOCK MOVEMENT REPORT
elseif ($report_type === 'movement') {
    $movement = $db->fetchAll(
        "SELECT p.id, p.name, c.name as category,
                COALESCE(SUM(CASE WHEN sm.type = 'in' THEN sm.quantity ELSE 0 END), 0) as received,
                COALESCE(SUM(CASE WHEN sm.type = 'out' THEN sm.quantity ELSE 0 END), 0) as sold,
                COALESCE(SUM(CASE WHEN sm.type = 'adjust' THEN sm.quantity ELSE 0 END), 0) as adjusted,
                p.stock_quantity as current_stock
         FROM products p
         LEFT JOIN categories c ON p.category_id = c.id
         LEFT JOIN stock_movements sm ON p.id = sm.product_id AND sm.created_date BETWEEN ? AND ?
         WHERE p.is_active = 1
         GROUP BY p.id, p.name, c.name, p.stock_quantity
         ORDER BY (COALESCE(SUM(CASE WHEN sm.type = 'in' THEN sm.quantity ELSE 0 END), 0) + COALESCE(SUM(CASE WHEN sm.type = 'out' THEN sm.quantity ELSE 0 END), 0)) DESC",
        [$date_from, $date_to]
    );
}

// AGING REPORT
elseif ($report_type === 'aging') {
    $aging = $db->fetchAll(
        "SELECT p.id, p.name, c.name as category,
                DATEDIFF(NOW(), p.created_at) as age_days,
                p.stock_quantity,
                CASE WHEN DATEDIFF(NOW(), p.created_at) <= 30 THEN '0-30 days'
                     WHEN DATEDIFF(NOW(), p.created_at) <= 60 THEN '31-60 days'
                     WHEN DATEDIFF(NOW(), p.created_at) <= 90 THEN '61-90 days'
                     ELSE '90+ days' END as age_group
         FROM products p
         LEFT JOIN categories c ON p.category_id = c.id
         WHERE p.is_active = 1
         ORDER BY p.created_at ASC"
    );
    
    // Group by age
    $age_summary = $db->fetchAll(
        "SELECT 
                CASE WHEN DATEDIFF(NOW(), p.created_at) <= 30 THEN '0-30 days'
                     WHEN DATEDIFF(NOW(), p.created_at) <= 60 THEN '31-60 days'
                     WHEN DATEDIFF(NOW(), p.created_at) <= 90 THEN '61-90 days'
                     ELSE '90+ days' END as age_group,
                COUNT(*) as product_count,
                COALESCE(SUM(p.stock_quantity), 0) as total_qty
         FROM products p
         WHERE p.is_active = 1
         GROUP BY age_group
         ORDER BY CASE age_group 
                  WHEN '0-30 days' THEN 1
                  WHEN '31-60 days' THEN 2
                  WHEN '61-90 days' THEN 3
                  ELSE 4 END"
    );
}

// REORDER REPORT
elseif ($report_type === 'reorder') {
    $reorder_items = $db->fetchAll(
        "SELECT p.id, p.name, c.name as category,
                p.stock_quantity, p.reorder_level, p.reorder_quantity,
                p.unit_cost,
                (p.reorder_quantity * p.unit_cost) as reorder_cost
         FROM products p
         LEFT JOIN categories c ON p.category_id = c.id
         WHERE p.is_active = 1 AND p.stock_quantity <= p.reorder_level
         ORDER BY p.stock_quantity ASC"
    );
    
    $reorder_summary = $db->fetch(
        "SELECT COUNT(*) as total_items, 
                COALESCE(SUM(p.reorder_quantity * p.unit_cost), 0) as total_cost
         FROM products p
         WHERE p.is_active = 1 AND p.stock_quantity <= p.reorder_level"
    );
}

$pageTitle = 'Inventory Reports';
include 'templates/header.php';
?>

<div class="row mb-4">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center">
            <h1 class="h3 mb-0">Inventory Reports</h1>
            <a href="<?php echo getBaseUrl(); ?>/reports.php" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left"></i> Back
            </a>
        </div>
    </div>
</div>

<!-- REPORT TABS -->
<ul class="nav nav-tabs mb-4">
    <li class="nav-item"><a class="nav-link <?php echo $report_type === 'stock_level' ? 'active' : ''; ?>" href="?type=stock_level">Stock Levels</a></li>
    <li class="nav-item"><a class="nav-link <?php echo $report_type === 'valuation' ? 'active' : ''; ?>" href="?type=valuation">Valuation</a></li>
    <li class="nav-item"><a class="nav-link <?php echo $report_type === 'movement' ? 'active' : ''; ?>" href="?type=movement&from=<?php echo $date_from; ?>&to=<?php echo $date_to; ?>">Movement</a></li>
    <li class="nav-item"><a class="nav-link <?php echo $report_type === 'aging' ? 'active' : ''; ?>" href="?type=aging">Aging</a></li>
    <li class="nav-item"><a class="nav-link <?php echo $report_type === 'reorder' ? 'active' : ''; ?>" href="?type=reorder">Reorder</a></li>
</ul>

<!-- STOCK LEVEL REPORT -->
<?php if ($report_type === 'stock_level'): ?>
<div class="card">
    <div class="card-header">Current Stock Levels</div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-sm">
                <thead>
                    <tr>
                        <th>Product</th>
                        <th>Category</th>
                        <th class="text-end">Qty</th>
                        <th class="text-end">Reorder Level</th>
                        <th class="text-end">Unit Cost</th>
                        <th class="text-end">Stock Value</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $total_value = 0; foreach ($stock_levels as $product): $total_value += $product['stock_value']; ?>
                    <tr>
                        <td><?php echo escape($product['name']); ?></td>
                        <td><?php echo escape($product['category'] ?? 'Uncategorized'); ?></td>
                        <td class="text-end"><?php echo (int)$product['stock_quantity']; ?></td>
                        <td class="text-end"><?php echo (int)$product['reorder_level']; ?></td>
                        <td class="text-end"><?php echo formatCurrency($product['unit_cost']); ?></td>
                        <td class="text-end"><?php echo formatCurrency($product['stock_value']); ?></td>
                        <td>
                            <?php
                            $badge = match($product['status']) {
                                'Out of Stock' => 'bg-danger',
                                'Low Stock' => 'bg-warning',
                                default => 'bg-success'
                            };
                            ?>
                            <span class="badge <?php echo $badge; ?>"><?php echo $product['status']; ?></span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <tr class="table-active">
                        <td colspan="5"><strong>Total Inventory Value</strong></td>
                        <td class="text-end"><strong><?php echo formatCurrency($total_value); ?></strong></td>
                        <td></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php endif; ?>

<!-- VALUATION REPORT -->
<?php if ($report_type === 'valuation'): ?>
<div class="row mb-4">
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">Valuation by Category</div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>Category</th>
                                <th class="text-end">Items</th>
                                <th class="text-end">Qty</th>
                                <th class="text-end">Value</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $grand_total = 0; foreach ($valuation_by_category as $cat): $grand_total += $cat['total_value']; ?>
                            <tr>
                                <td><?php echo escape($cat['name']); ?></td>
                                <td class="text-end"><?php echo (int)$cat['product_count']; ?></td>
                                <td class="text-end"><?php echo (int)$cat['total_qty']; ?></td>
                                <td class="text-end"><?php echo formatCurrency($cat['total_value']); ?></td>
                            </tr>
                            <?php endforeach; ?>
                            <tr class="table-active">
                                <td><strong>Total</strong></td>
                                <td class="text-end">-</td>
                                <td class="text-end">-</td>
                                <td class="text-end"><strong><?php echo formatCurrency($grand_total); ?></strong></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">Dead Stock (90+ days untouched)</div>
            <div class="card-body">
                <?php if (empty($dead_stock)): ?>
                <div class="alert alert-success mb-0">No dead stock items found.</div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead>
                            <tr><th>Product</th><th class="text-end">Qty</th><th class="text-end">Value</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($dead_stock as $item): ?>
                            <tr>
                                <td><?php echo escape($item['name']); ?></td>
                                <td class="text-end"><?php echo (int)$item['stock_quantity']; ?></td>
                                <td class="text-end"><?php echo formatCurrency($item['stock_value']); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php endif; ?>

<!-- MOVEMENT REPORT -->
<?php if ($report_type === 'movement'): ?>
<div class="card">
    <div class="card-header">Stock Movement (<?php echo formatDate($date_from); ?> to <?php echo formatDate($date_to); ?>)</div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-sm">
                <thead>
                    <tr>
                        <th>Product</th>
                        <th class="text-end">Received</th>
                        <th class="text-end">Sold</th>
                        <th class="text-end">Adjusted</th>
                        <th class="text-end">Current</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($movement as $prod): ?>
                    <tr>
                        <td><?php echo escape($prod['name']); ?></td>
                        <td class="text-end text-success">+<?php echo (int)$prod['received']; ?></td>
                        <td class="text-end text-danger">-<?php echo (int)$prod['sold']; ?></td>
                        <td class="text-end"><?php echo ($prod['adjusted'] >= 0 ? '+' : ''); ?><?php echo (int)$prod['adjusted']; ?></td>
                        <td class="text-end"><?php echo (int)$prod['current_stock']; ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php endif; ?>

<!-- AGING REPORT -->
<?php if ($report_type === 'aging'): ?>
<div class="row mb-4">
    <div class="col-md-4">
        <div class="card">
            <div class="card-header">Age Distribution Summary</div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead>
                            <tr><th>Age Group</th><th class="text-end">Items</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($age_summary as $group): ?>
                            <tr>
                                <td><?php echo escape($group['age_group']); ?></td>
                                <td class="text-end"><?php echo (int)$group['product_count']; ?></td>
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
            <div class="card-header">Aged Products Details</div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead>
                            <tr><th>Product</th><th>Age Group</th><th class="text-end">Qty</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($aging as $prod): ?>
                            <tr>
                                <td><?php echo escape($prod['name']); ?></td>
                                <td><span class="badge bg-info"><?php echo escape($prod['age_group']); ?></span></td>
                                <td class="text-end"><?php echo (int)$prod['stock_quantity']; ?></td>
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

<!-- REORDER REPORT -->
<?php if ($report_type === 'reorder' && !empty($reorder_items)): ?>
<div class="alert alert-warning mb-4">
    <strong><?php echo (int)$reorder_summary['total_items']; ?> items</strong> need to be reordered. 
    <strong>Estimated cost: <?php echo formatCurrency($reorder_summary['total_cost']); ?></strong>
</div>

<div class="card">
    <div class="card-header">Reorder Required Items</div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-sm">
                <thead>
                    <tr>
                        <th>Product</th>
                        <th class="text-end">Current Qty</th>
                        <th class="text-end">Reorder Level</th>
                        <th class="text-end">Qty to Reorder</th>
                        <th class="text-end">Unit Cost</th>
                        <th class="text-end">Reorder Cost</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($reorder_items as $item): ?>
                    <tr>
                        <td><?php echo escape($item['name']); ?></td>
                        <td class="text-end text-danger"><?php echo (int)$item['stock_quantity']; ?></td>
                        <td class="text-end"><?php echo (int)$item['reorder_level']; ?></td>
                        <td class="text-end text-primary"><?php echo (int)$item['reorder_quantity']; ?></td>
                        <td class="text-end"><?php echo formatCurrency($item['unit_cost']); ?></td>
                        <td class="text-end"><?php echo formatCurrency($item['reorder_cost']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php endif; ?>

<?php include 'templates/footer.php'; ?>
