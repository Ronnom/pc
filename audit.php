<?php
/**
 * Inventory Audit Page
 * Physical stock counts and variance calculations
 */

require_once 'includes/init.php';
requireLogin();
requirePermission('inventory.view');

$pageTitle = 'Inventory Audits';
$stockModule = new StockManagementModule();
$productsModule = new ProductsModule();
$db = getDB();

$action = $_GET['action'] ?? 'list';
$id = $_GET['id'] ?? null;

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCSRF();
    
    try {
        if ($action === 'add') {
            $data = [
                'location_id' => !empty($_POST['location_id']) ? (int)$_POST['location_id'] : null,
                'audit_date' => $_POST['audit_date'] ?? date(DATE_FORMAT),
                'notes' => sanitize($_POST['notes'] ?? '')
            ];
            
            $auditId = $stockModule->createInventoryAudit($data);
            setFlashMessage('success', 'Audit created successfully.');
            redirect(getBaseUrl() . "/audit.php?action=count&id={$auditId}");
        } elseif ($action === 'save_counts' && $id) {
            // Save counted quantities
            if (isset($_POST['counts']) && is_array($_POST['counts'])) {
                $db->beginTransaction();
                
                    try {
                        $totalItems = 0;
                        $itemsCounted = 0;
                        $varianceCount = 0;
                        $varianceValue = 0;
                        
                        foreach ($_POST['counts'] as $productId => $countData) {
                            $product = $productsModule->getProduct($productId);
                            $systemQty = $product['stock_quantity'];
                            $countedQty = (int)$countData['quantity'];
                            $variance = $countedQty - $systemQty;
                            
                            $varianceVal = abs($variance) * $product['cost_price'];
                            
                            $db->insert('inventory_audit_items', [
                            'audit_id' => $id,
                            'product_id' => $productId,
                            'system_quantity' => $systemQty,
                            'counted_quantity' => $countedQty,
                            'variance' => $variance,
                            'variance_value' => $varianceVal,
                            'notes' => sanitize($countData['notes'] ?? '')
                        ]);
                        
                        $totalItems++;
                        if ($countedQty > 0) $itemsCounted++;
                        if ($variance != 0) {
                            $varianceCount++;
                            $varianceValue += $varianceVal;
                        }
                    }
                    
                    // Update audit totals
                    $db->update('inventory_audits', [
                        'total_items' => $totalItems,
                        'items_counted' => $itemsCounted,
                        'variance_count' => $varianceCount,
                        'variance_value' => $varianceValue,
                        'status' => 'completed'
                    ], 'id = ?', [$id]);
                    
                    $db->commit();
                    
                    setFlashMessage('success', 'Audit counts saved successfully.');
                    redirect(getBaseUrl() . "/audit.php?view={$id}");
                } catch (Exception $e) {
                    $db->rollback();
                    throw $e;
                }
            }
        } elseif ($action === 'generate_adjustments' && $id) {
            // Generate adjustments from audit variances
            $auditItems = $db->fetchAll(
                "SELECT * FROM inventory_audit_items WHERE audit_id = ? AND variance != 0",
                [$id]
            );
            
            foreach ($auditItems as $item) {
                $stockModule->createStockAdjustment(
                    $item['product_id'],
                    $item['counted_quantity'],
                    'Audit variance',
                    'discrepancy',
                    "From audit #{$id}"
                );
            }
            
            setFlashMessage('success', 'Adjustments generated from audit.');
            redirect(getBaseUrl() . "/audit.php?view={$id}");
        }
    } catch (Exception $e) {
        setFlashMessage('error', $e->getMessage());
    }
}

// Get locations
$locations = $stockModule->getLocations();

// Get audits
$audits = $db->fetchAll(
    "SELECT ia.*, l.name as location_name, u.username as created_by_name 
     FROM inventory_audits ia 
     LEFT JOIN stock_locations l ON ia.location_id = l.id 
     LEFT JOIN users u ON ia.created_by = u.id 
     ORDER BY ia.created_at DESC 
     LIMIT 50"
);

include 'templates/header.php';
?>

<?php if ($action === 'list'): ?>
<div class="row mb-4">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center">
            <h1 class="h3 mb-0">Inventory Audits</h1>
            <a href="<?php echo getBaseUrl(); ?>/audit.php?action=add" class="btn btn-primary">
                <i class="bi bi-plus-circle"></i> New Audit
            </a>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <?php if (empty($audits)): ?>
            <p class="text-muted text-center mb-0">No audits found</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>Audit #</th>
                            <th>Date</th>
                            <th>Location</th>
                            <th>Status</th>
                            <th>Items</th>
                            <th>Variances</th>
                            <th>Variance Value</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($audits as $audit): ?>
                        <tr>
                            <td><a href="<?php echo getBaseUrl(); ?>/audit.php?view=<?php echo $audit['id']; ?>"><?php echo escape($audit['audit_number']); ?></a></td>
                            <td><?php echo formatDate($audit['audit_date']); ?></td>
                            <td><?php echo escape($audit['location_name'] ?? 'All Locations'); ?></td>
                            <td><?php echo getStatusBadge($audit['status']); ?></td>
                            <td><?php echo $audit['items_counted']; ?> / <?php echo $audit['total_items']; ?></td>
                            <td><?php echo $audit['variance_count']; ?></td>
                            <td><?php echo formatCurrency($audit['variance_value']); ?></td>
                            <td>
                                <a href="<?php echo getBaseUrl(); ?>/audit.php?view=<?php echo $audit['id']; ?>" class="btn btn-sm btn-outline-primary">
                                    <i class="bi bi-eye"></i>
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php elseif ($action === 'add'): ?>
<div class="row mb-4">
    <div class="col-12">
        <h1 class="h3 mb-0">New Inventory Audit</h1>
    </div>
</div>

<div class="row">
    <div class="col-lg-6">
        <div class="card">
            <div class="card-body">
                <form method="POST" action="">
                    <?php echo csrfField(); ?>
                    
                    <div class="mb-3">
                        <label for="location_id" class="form-label">Location (Optional)</label>
                        <select class="form-select" id="location_id" name="location_id">
                            <option value="">All Locations</option>
                            <?php foreach ($locations as $loc): ?>
                            <option value="<?php echo $loc['id']; ?>"><?php echo escape($loc['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label for="audit_date" class="form-label">Audit Date <span class="text-danger">*</span></label>
                        <input type="date" class="form-control" id="audit_date" name="audit_date" required value="<?php echo date(DATE_FORMAT); ?>">
                    </div>
                    
                    <div class="mb-3">
                        <label for="notes" class="form-label">Notes</label>
                        <textarea class="form-control" id="notes" name="notes" rows="3"></textarea>
                    </div>
                    
                    <div class="d-flex justify-content-between">
                        <a href="<?php echo getBaseUrl(); ?>/audit.php" class="btn btn-secondary">Cancel</a>
                        <button type="submit" class="btn btn-primary">Create Audit</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php elseif ($action === 'count' && $id): ?>
<?php
$audit = $db->fetchOne("SELECT * FROM inventory_audits WHERE id = ?", [$id]);
$products = $productsModule->getProducts([], 1000, 0);
?>
<div class="row mb-4">
    <div class="col-12">
        <h1 class="h3 mb-0">Enter Counts: <?php echo escape($audit['audit_number']); ?></h1>
    </div>
</div>

<div class="row">
    <div class="col-12">
        <div class="card">
            <div class="card-body">
                <form method="POST" action="">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="save_counts">
                    
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Product</th>
                                    <th>SKU</th>
                                    <th>System Qty</th>
                                    <th>Counted Qty</th>
                                    <th>Variance</th>
                                    <th>Notes</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($products as $product): ?>
                                <tr>
                                    <td><?php echo escape($product['name']); ?></td>
                                    <td><?php echo escape($product['sku']); ?></td>
                                    <td>
                                        <span class="badge bg-info"><?php echo $product['stock_quantity']; ?></span>
                                    </td>
                                    <td>
                                        <input type="number" class="form-control form-control-sm" 
                                               name="counts[<?php echo $product['id']; ?>][quantity]" 
                                               value="<?php echo $product['stock_quantity']; ?>" 
                                               min="0" style="width: 100px;">
                                    </td>
                                    <td id="variance_<?php echo $product['id']; ?>">0</td>
                                    <td>
                                        <input type="text" class="form-control form-control-sm" 
                                               name="counts[<?php echo $product['id']; ?>][notes]" 
                                               style="width: 200px;">
                                    </td>
                                </tr>
                                <script>
                                document.querySelector('input[name="counts[<?php echo $product['id']; ?>][quantity]"]').addEventListener('input', function() {
                                    const systemQty = <?php echo $product['stock_quantity']; ?>;
                                    const countedQty = parseInt(this.value) || 0;
                                    const variance = countedQty - systemQty;
                                    const varianceCell = document.getElementById('variance_<?php echo $product['id']; ?>');
                                    varianceCell.textContent = variance;
                                    varianceCell.className = variance > 0 ? 'text-success' : (variance < 0 ? 'text-danger' : '');
                                });
                                </script>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <div class="mt-4">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-save"></i> Save Counts
                        </button>
                        <a href="<?php echo getBaseUrl(); ?>/audit.php" class="btn btn-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php elseif ($action === 'view' && $id): ?>
<?php
$audit = $db->fetchOne(
    "SELECT ia.*, l.name as location_name, u.username as created_by_name 
     FROM inventory_audits ia 
     LEFT JOIN stock_locations l ON ia.location_id = l.id 
     LEFT JOIN users u ON ia.created_by = u.id 
     WHERE ia.id = ?",
    [$id]
);

$auditItems = $db->fetchAll(
    "SELECT iai.*, p.name as product_name, p.sku 
     FROM inventory_audit_items iai 
     INNER JOIN products p ON iai.product_id = p.id 
     WHERE iai.audit_id = ? 
     ORDER BY ABS(iai.variance) DESC",
    [$id]
);
?>
<div class="row mb-4">
    <div class="col-12">
        <h1 class="h3 mb-0">Audit: <?php echo escape($audit['audit_number']); ?></h1>
    </div>
</div>

<div class="row">
    <div class="col-md-8">
        <div class="card mb-4">
            <div class="card-header">Audit Summary</div>
            <div class="card-body">
                <table class="table">
                    <tr>
                        <th width="200">Audit Number</th>
                        <td><?php echo escape($audit['audit_number']); ?></td>
                    </tr>
                    <tr>
                        <th>Date</th>
                        <td><?php echo formatDate($audit['audit_date']); ?></td>
                    </tr>
                    <tr>
                        <th>Location</th>
                        <td><?php echo escape($audit['location_name'] ?? 'All Locations'); ?></td>
                    </tr>
                    <tr>
                        <th>Status</th>
                        <td><?php echo getStatusBadge($audit['status']); ?></td>
                    </tr>
                    <tr>
                        <th>Total Items</th>
                        <td><?php echo $audit['total_items']; ?></td>
                    </tr>
                    <tr>
                        <th>Items Counted</th>
                        <td><?php echo $audit['items_counted']; ?></td>
                    </tr>
                    <tr>
                        <th>Variances</th>
                        <td><?php echo $audit['variance_count']; ?></td>
                    </tr>
                    <tr>
                        <th>Variance Value</th>
                        <td><strong><?php echo formatCurrency($audit['variance_value']); ?></strong></td>
                    </tr>
                </table>
            </div>
        </div>
        
        <div class="card">
            <div class="card-header">Audit Items</div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th>System Qty</th>
                                <th>Counted Qty</th>
                                <th>Variance</th>
                                <th>Value Impact</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($auditItems as $item): ?>
                            <tr class="<?php echo $item['variance'] != 0 ? 'table-warning' : ''; ?>">
                                <td><?php echo escape($item['product_name']); ?> (<?php echo escape($item['sku']); ?>)</td>
                                <td><?php echo $item['system_quantity']; ?></td>
                                <td><?php echo $item['counted_quantity']; ?></td>
                                <td>
                                    <span class="badge bg-<?php echo $item['variance'] > 0 ? 'success' : ($item['variance'] < 0 ? 'danger' : 'secondary'); ?>">
                                        <?php echo $item['variance'] > 0 ? '+' : ''; ?><?php echo $item['variance']; ?>
                                    </span>
                                </td>
                                <td><?php echo formatCurrency($item['variance_value']); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <?php if ($audit['status'] === 'completed' && $audit['variance_count'] > 0): ?>
                <div class="mt-3">
                    <form method="POST" action="" onsubmit="return confirm('Generate stock adjustments for all variances?');">
                        <?php echo csrfField(); ?>
                        <input type="hidden" name="action" value="generate_adjustments">
                        <button type="submit" class="btn btn-warning">
                            <i class="bi bi-arrow-left-right"></i> Generate Adjustments from Variances
                        </button>
                    </form>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php endif; ?>

<?php include 'templates/footer.php'; ?>

