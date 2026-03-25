<?php
/**
 * Inventory Dashboard Page
 * Landing page for inventory operations and monitoring
 */

require_once 'includes/init.php';
requireLogin();
requirePermission('inventory.view');

$pageTitle = 'Inventory Dashboard';
$db = getDB();
$inventoryModule = new InventoryModule();

// Get current date for display
$currentDate = date('F j, Y');
$lastUpdated = date('M j, Y g:i A');

// Database schema checks
$productsColumns = $db->fetchAll(
    "SELECT COLUMN_NAME
     FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products'"
);
$productsColumnMap = [];
foreach ($productsColumns as $column) {
    $productsColumnMap[$column['COLUMN_NAME']] = true;
}
$hasDeletedAt = isset($productsColumnMap['deleted_at']);
$thresholdColumn = isset($productsColumnMap['min_stock_level'])
    ? 'min_stock_level'
    : (isset($productsColumnMap['reorder_level']) ? 'reorder_level' : null);

$stockMovementsTable = $db->fetchOne(
    "SELECT COUNT(*) AS cnt
     FROM INFORMATION_SCHEMA.TABLES
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_movements'"
);
$hasStockMovements = (int)($stockMovementsTable['cnt'] ?? 0) > 0;

$productsWhere = $hasDeletedAt
    ? "WHERE deleted_at IS NULL"
    : "";

$lowStockExpr = $thresholdColumn
    ? "stock_quantity <= {$thresholdColumn} AND is_active = 1"
    : "stock_quantity <= 5 AND is_active = 1";

$totals = $db->fetchOne(
    "SELECT
        COUNT(*) AS total_products,
        SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) AS active_products,
        SUM(CASE WHEN stock_quantity = 0 THEN 1 ELSE 0 END) AS out_of_stock,
        SUM(CASE WHEN {$lowStockExpr} THEN 1 ELSE 0 END) AS low_stock,
        SUM(CASE WHEN is_active = 1 THEN stock_quantity ELSE 0 END) AS total_units,
        SUM(CASE WHEN is_active = 1 THEN (stock_quantity * cost_price) ELSE 0 END) AS inventory_value
     FROM products
     {$productsWhere}"
);

$recentMovements = [];
if ($hasStockMovements) {
    $recentMovements = $db->fetchAll(
        "SELECT sm.created_at, sm.movement_type, sm.quantity, sm.reference_type, sm.reference_id,
                p.name AS product_name, p.sku
         FROM stock_movements sm
         INNER JOIN products p ON p.id = sm.product_id
         ORDER BY sm.created_at DESC
         LIMIT 10"
    );
}

$lowStockProducts = $inventoryModule->getLowStockProducts();

$inventoryStyles = <<<HTML
<style>
  .inventory-page {
    padding: 24px 20px 40px;
    background: #f6f8fb;
  }
  .inventory-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    background: #ffffff;
    border-radius: 16px;
    padding: 20px 24px;
    box-shadow: 0 10px 30px rgba(15, 23, 42, 0.08);
    margin-bottom: 20px;
  }
  .inventory-header h1 {
    margin: 0 0 6px;
    font-size: 1.6rem;
    font-weight: 700;
    color: #0f172a;
  }
  .inventory-header .section-subtitle {
    color: #64748b;
  }
  .inventory-chip {
    background: #e0f2fe;
    color: #0369a1;
    font-weight: 600;
    padding: 8px 14px;
    border-radius: 999px;
  }
  .inventory-page .card {
    border: none;
    border-radius: 16px;
    box-shadow: 0 12px 30px rgba(15, 23, 42, 0.06);
  }
  .inventory-page .card-header {
    background: transparent;
    border-bottom: 1px solid #eef2f7;
    padding: 16px 20px;
  }
  .inventory-page .card-body {
    padding: 18px 20px 22px;
  }
  .inventory-page .table thead th {
    color: #64748b;
    font-weight: 600;
    border-bottom: 1px solid #eef2f7;
  }
  .inventory-page .table tbody tr {
    border-bottom: 1px solid #f1f5f9;
  }
  .inventory-page .btn-outline-secondary {
    border-color: #cbd5f5;
    color: #334155;
  }
  .inventory-page .btn-outline-secondary:hover {
    border-color: #94a3b8;
    color: #0f172a;
  }
  .inventory-page .badge {
    border-radius: 999px;
  }
</style>
HTML;

include 'templates/header.php';
?>

<?php echo $inventoryStyles; ?>

<div class="container-fluid inventory-page">
<!-- Header Section -->
<div class="inventory-header">
    <div class="inventory-title">
        <h1>Inventory Dashboard</h1>
        <small class="section-subtitle">Last updated: <?php echo $lastUpdated; ?> • <?php echo $currentDate; ?></small>
    </div>
    <span class="inventory-chip">Inventory Health</span>
</div>
<!-- Key Metrics Cards -->
<div class="row g-3 mb-4">
    <?php echo renderStatCard(
        'Active Products',
        number_format((int)($totals['active_products'] ?? 0)),
        'Currently available',
        'bi-box-seam',
        'primary'
    ); ?>

    <?php echo renderStatCard(
        'Total Units',
        number_format((int)($totals['total_units'] ?? 0)),
        'In stock across all products',
        'bi-stack',
        'info'
    ); ?>

    <?php echo renderStatCard(
        'Low Stock',
        number_format((int)($totals['low_stock'] ?? 0)),
        'Below reorder level',
        'bi-exclamation-triangle-fill',
        'warning',
        getBaseUrl() . '/product_list.php?low_stock=1'
    ); ?>

    <?php echo renderStatCard(
        'Out of Stock',
        number_format((int)($totals['out_of_stock'] ?? 0)),
        'Zero inventory items',
        'bi-x-circle-fill',
        'danger',
        getBaseUrl() . '/product_list.php?out_of_stock=1'
    ); ?>

    <?php echo renderStatCard(
        'Inventory Value',
        formatCurrency((float)($totals['inventory_value'] ?? 0)),
        'Total stock value at cost',
        'bi-cash-stack',
        'success'
    ); ?>
</div>

<!-- Low Stock Alerts & Recent Movements -->
<div class="row g-4">
    <!-- Low Stock Alerts Section -->
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="bi bi-exclamation-triangle text-warning me-2"></i>Low Stock Alerts</h5>
                <div class="btn-group btn-group-sm">
                    <a href="<?php echo getBaseUrl(); ?>/product_list.php?low_stock=1" class="btn btn-outline-secondary btn-sm">
                        <i class="bi bi-eye"></i> View All
                    </a>
                    <a href="<?php echo getBaseUrl(); ?>/inventory_reports.php?type=stock_level" class="btn btn-outline-secondary btn-sm">
                        <i class="bi bi-gear"></i> Settings
                    </a>
                </div>
            </div>
            <div class="card-body">
                <?php if (empty($lowStockProducts)): ?>
                    <?php echo renderEmptyState(
                        'bi-check-circle-fill',
                        'All Stock Levels Healthy',
                        'No products are currently below their reorder levels. Your inventory is well-maintained!',
                        null,
                        'success'
                    ); ?>
                <?php else: ?>
                    <div class="table-responsive">
                        <?php
                        $lowStockHeaders = [
                            ['label' => 'Product', 'sortable' => true, 'key' => 'name'],
                            ['label' => 'SKU', 'sortable' => true, 'key' => 'sku'],
                            ['label' => 'Current', 'sortable' => true, 'key' => 'stock_quantity', 'class' => 'text-center'],
                            ['label' => 'Min Level', 'sortable' => true, 'key' => 'min_stock_level', 'class' => 'text-center'],
                            ['label' => 'Action', 'class' => 'text-center']
                        ];

                        $lowStockData = array_map(function($product) {
                            $isOutOfStock = (int)$product['stock_quantity'] === 0;
                            return [
                                'name' => '<div class="d-flex align-items-center">
                                    <div>
                                        <div class="fw-medium">' . escape($product['name']) . '</div>
                                        <small class="text-muted">' . escape($product['sku']) . '</small>
                                    </div>
                                </div>',
                                'sku' => escape($product['sku']),
                                'stock_quantity' => '<span class="badge ' . ($isOutOfStock ? 'bg-danger' : 'bg-warning') . ' fs-6 px-2 py-1">' .
                                    number_format((int)$product['stock_quantity']) . '</span>',
                                'min_stock_level' => '<span class="text-muted">' . number_format((int)getProductThresholdValue($product)) . '</span>',
                                'action' => '<a href="' . getBaseUrl() . '/stock_in.php?product_id=' . $product['id'] . '" class="btn btn-sm btn-outline-primary">
                                    <i class="bi bi-plus-circle"></i> Restock
                                </a>'
                            ];
                        }, array_slice($lowStockProducts, 0, 8));

                        echo renderTable($lowStockHeaders, $lowStockData, [
                            'striped' => false,
                            'hover' => true,
                            'compact' => true,
                            'empty_message' => 'No low stock items found.',
                            'show_headers' => false
                        ]);
                        ?>

                        <?php if (count($lowStockProducts) > 8): ?>
                        <div class="text-center mt-3">
                            <a href="<?php echo getBaseUrl(); ?>/product_list.php?low_stock=1" class="btn btn-outline-secondary btn-sm">
                                View <?php echo count($lowStockProducts) - 8; ?> more items
                            </a>
                        </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Recent Stock Movements -->
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="bi bi-arrow-left-right me-2"></i>Recent Stock Movements</h5>
                <a href="<?php echo getBaseUrl(); ?>/stock_tracking.php" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-eye"></i> View All
                </a>
            </div>
            <div class="card-body">
                <?php if (empty($recentMovements)): ?>
                    <?php echo renderEmptyState(
                        'bi-graph-up',
                        'No Recent Movements',
                        'Stock movement history will appear here as inventory changes occur.',
                        ['url' => getBaseUrl() . '/stock_tracking.php', 'text' => 'View Stock History', 'icon' => 'bi-clock-history']
                    ); ?>
                <?php else: ?>
                    <div class="table-responsive">
                        <?php
                        $movementHeaders = [
                            ['label' => 'Date & Time', 'sortable' => true, 'key' => 'created_at', 'class' => 'text-nowrap'],
                            ['label' => 'Product', 'sortable' => true, 'key' => 'product_name'],
                            ['label' => 'Type', 'class' => 'text-center'],
                            ['label' => 'Quantity', 'sortable' => true, 'key' => 'quantity', 'class' => 'text-end']
                        ];

                        $movementData = array_map(function($movement) {
                            $isInbound = $movement['movement_type'] === 'in';
                            return [
                                'created_at' => '<small class="text-muted">' . formatDateTime($movement['created_at']) . '</small>',
                                'product_name' => '<div>
                                    <div class="fw-medium text-truncate" style="max-width: 200px;" title="' . escape($movement['product_name']) . '">' .
                                        escape($movement['product_name']) . '</div>
                                    <small class="text-muted">' . escape($movement['sku']) . '</small>
                                </div>',
                                'type' => '<span class="badge ' . ($isInbound ? 'bg-success' : 'bg-danger') . ' fs-7 px-2 py-1">
                                    <i class="bi bi-' . ($isInbound ? 'arrow-down-circle' : 'arrow-up-circle') . ' me-1"></i>' .
                                    strtoupper($movement['movement_type']) . '
                                </span>',
                                'quantity' => '<span class="fw-bold ' . ($isInbound ? 'text-success' : 'text-danger') . '">' .
                                    ($isInbound ? '+' : '-') . number_format(abs((int)$movement['quantity'])) . '</span>'
                            ];
                        }, $recentMovements);

                        echo renderTable($movementHeaders, $movementData, [
                            'striped' => false,
                            'hover' => true,
                            'compact' => true,
                            'empty_message' => 'No stock movements found.',
                            'show_headers' => false
                        ]);
                        ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
</div>

<?php include 'templates/footer.php'; ?>







