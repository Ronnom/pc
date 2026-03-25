<?php
/**
 * Stock Tracking Page
 * Real-time stock levels with location, serial, and batch tracking
 */

require_once 'includes/init.php';
requireLogin();
requirePermission('inventory.view');

$pageTitle = 'Stock Tracking';
$stockModule = new StockManagementModule();
$productsModule = new ProductsModule();
$db = getDB();

$productId = $_GET['product_id'] ?? null;
$locationId = $_GET['location_id'] ?? null;

function tableExists($db, $tableName) {
    try {
        $row = $db->fetchOne(
            "SELECT COUNT(*) AS cnt
             FROM INFORMATION_SCHEMA.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?",
            [$tableName]
        );
        return (int)($row['cnt'] ?? 0) > 0;
    } catch (Exception $e) {
        return false;
    }
}

// Get locations
$locations = $stockModule->getLocations();

// Get stock movements
$filters = [];
if ($productId) $filters['product_id'] = $productId;
if ($locationId) $filters['location_id'] = $locationId;

$movements = [];
if (tableExists($db, 'stock_movements')) {
    try {
        $movements = $db->fetchAll(
            "SELECT sm.*, p.name as product_name, p.sku, u.username as created_by_name
             FROM stock_movements sm
             INNER JOIN products p ON sm.product_id = p.id
             LEFT JOIN users u ON sm.created_by = u.id
             WHERE 1=1 " .
             ($productId ? " AND sm.product_id = " . (int)$productId : "") .
             ($locationId ? " AND sm.location_id = " . (int)$locationId : "") .
             " ORDER BY sm.created_at DESC
             LIMIT 100"
        );
    } catch (Exception $e) {
        try {
            $movements = $db->fetchAll(
                "SELECT sm.*, p.name as product_name, p.sku, '' as created_by_name
                 FROM stock_movements sm
                 INNER JOIN products p ON sm.product_id = p.id
                 WHERE 1=1 " .
                 ($productId ? " AND sm.product_id = " . (int)$productId : "") .
                 " ORDER BY sm.id DESC
                 LIMIT 100"
            );
        } catch (Exception $e2) {
            $movements = [];
        }
    }
}

// Get product details if selected
$product = null;
$productLocations = [];
$serialNumbers = [];
if ($productId) {
    $product = $productsModule->getProduct($productId);
    
    // Get stock by location
    if (tableExists($db, 'product_locations') && tableExists($db, 'stock_locations')) {
        try {
            $productLocations = $db->fetchAll(
                "SELECT pl.*, l.name as location_name
                 FROM product_locations pl
                 INNER JOIN stock_locations l ON pl.location_id = l.id
                 WHERE pl.product_id = ?",
                [$productId]
            );
        } catch (Exception $e) {
            $productLocations = [];
        }
    } else {
        $productLocations = [];
    }
    
    // Get serial numbers
    if (tableExists($db, 'product_serial_numbers')) {
        try {
            if (tableExists($db, 'stock_locations')) {
                $serialNumbers = $db->fetchAll(
                    "SELECT psn.*, l.name as location_name
                     FROM product_serial_numbers psn
                     LEFT JOIN stock_locations l ON psn.location_id = l.id
                     WHERE psn.product_id = ?
                     ORDER BY psn.created_at DESC",
                    [$productId]
                );
            } else {
                $serialNumbers = $db->fetchAll(
                    "SELECT psn.*, '' as location_name
                     FROM product_serial_numbers psn
                     WHERE psn.product_id = ?
                     ORDER BY psn.created_at DESC",
                    [$productId]
                );
            }
        } catch (Exception $e) {
            $serialNumbers = [];
        }
    }
}

// Get all products for filter
$products = $productsModule->getProducts([], 1000, 0);

include 'templates/header.php';
?>

<div class="row mb-4">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center">
            <h1 class="h3 mb-0">Stock Tracking</h1>
        </div>
    </div>
</div>

<!-- Filters -->
<div class="card mb-4">
    <div class="card-body">
        <form method="GET" class="row g-3">
            <div class="col-md-5">
                <label class="form-label">Product</label>
                <select class="form-select" name="product_id" onchange="this.form.submit()">
                    <option value="">All Products</option>
                    <?php foreach ($products as $prod): ?>
                    <option value="<?php echo $prod['id']; ?>" <?php echo $productId == $prod['id'] ? 'selected' : ''; ?>>
                        <?php echo escape($prod['name']); ?> (<?php echo escape($prod['sku']); ?>)
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-5">
                <label class="form-label">Location</label>
                <select class="form-select" name="location_id" onchange="this.form.submit()">
                    <option value="">All Locations</option>
                    <?php foreach ($locations as $loc): ?>
                    <option value="<?php echo $loc['id']; ?>" <?php echo $locationId == $loc['id'] ? 'selected' : ''; ?>>
                        <?php echo escape($loc['name']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">&nbsp;</label>
                <a href="<?php echo getBaseUrl(); ?>/stock_tracking.php" class="btn btn-outline-secondary w-100">Reset</a>
            </div>
        </form>
    </div>
</div>

<?php if ($product): ?>
<!-- Product Details -->
<div class="row mb-4">
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">Product Information</div>
            <div class="card-body">
                <table class="table table-sm">
                    <tr>
                        <th width="150">Name</th>
                        <td><?php echo escape($product['name']); ?></td>
                    </tr>
                    <tr>
                        <th>SKU</th>
                        <td><?php echo escape($product['sku']); ?></td>
                    </tr>
                    <tr>
                        <th>Total Stock</th>
                        <td><strong><?php echo $product['stock_quantity']; ?></strong></td>
                    </tr>
                    <tr>
                        <th>Cost Price</th>
                        <td><?php echo formatCurrency($product['cost_price']); ?></td>
                    </tr>
                    <tr>
                        <th>Total Value</th>
                        <td><strong><?php echo formatCurrency($product['stock_quantity'] * $product['cost_price']); ?></strong></td>
                    </tr>
                </table>
            </div>
        </div>
    </div>
    
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">Stock by Location</div>
            <div class="card-body">
                <?php if (empty($productLocations)): ?>
                    <p class="text-muted mb-0">No location-specific stock tracked</p>
                <?php else: ?>
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>Location</th>
                                <th>Quantity</th>
                                <th>Last Updated</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($productLocations as $pl): ?>
                            <tr>
                                <td><?php echo escape($pl['location_name']); ?></td>
                                <td><strong><?php echo $pl['quantity']; ?></strong></td>
                                <td><?php echo formatDateTime($pl['updated_at']); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Serial Numbers -->
<?php if (!empty($serialNumbers)): ?>
<div class="row mb-4">
    <div class="col-12">
        <div class="card">
            <div class="card-header">Serial Number Tracking</div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>Serial Number</th>
                                <th>Location</th>
                                <th>Status</th>
                                <th>Transaction</th>
                                <th>Created</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($serialNumbers as $sn): ?>
                            <tr>
                                <td><code><?php echo escape($sn['serial_number']); ?></code></td>
                                <td><?php echo escape($sn['location_name'] ?? '-'); ?></td>
                                <td><?php echo getStatusBadge($sn['status']); ?></td>
                                <td><?php echo $sn['transaction_id'] ? 'TXN-' . $sn['transaction_id'] : '-'; ?></td>
                                <td><?php echo formatDateTime($sn['created_at']); ?></td>
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

<?php endif; ?>

<!-- Stock Movements -->
<div class="row">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">Recent Stock Movements</h5>
            </div>
            <div class="card-body">
                <?php if (empty($movements)): ?>
                    <p class="text-muted text-center mb-0">No stock movements found</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Date/Time</th>
                                    <th>Product</th>
                                    <th>Type</th>
                                    <th>Quantity</th>
                                    <th>Reference</th>
                                    <th>Notes</th>
                                    <th>By</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($movements as $movement): ?>
                                <tr>
                                    <td><?php echo formatDateTime($movement['created_at']); ?></td>
                                    <td>
                                        <?php echo escape($movement['product_name']); ?><br>
                                        <small class="text-muted"><?php echo escape($movement['sku']); ?></small>
                                    </td>
                                    <td>
                                        <?php
                                        $badgeClass = 'secondary';
                                        if ($movement['movement_type'] === 'in') $badgeClass = 'success';
                                        elseif ($movement['movement_type'] === 'out') $badgeClass = 'danger';
                                        ?>
                                        <span class="badge bg-<?php echo $badgeClass; ?>">
                                            <?php echo ucfirst($movement['movement_type']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <strong class="<?php echo $movement['movement_type'] === 'in' ? 'text-success' : 'text-danger'; ?>">
                                            <?php echo $movement['movement_type'] === 'in' ? '+' : '-'; ?><?php echo $movement['quantity']; ?>
                                        </strong>
                                    </td>
                                    <td>
                                        <?php
                                        if ($movement['reference_type'] && $movement['reference_id']) {
                                            echo ucfirst($movement['reference_type']) . ' #' . $movement['reference_id'];
                                        } else {
                                            echo '-';
                                        }
                                        ?>
                                    </td>
                                    <td><?php echo escape($movement['notes'] ?? '-'); ?></td>
                                    <td><?php echo escape($movement['created_by_name'] ?? '-'); ?></td>
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

<?php include 'templates/footer.php'; ?>

