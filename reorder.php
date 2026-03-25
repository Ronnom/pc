<?php
/**
 * Reorder Management Page
 * Suggested reorder list and PO generation
 */

require_once 'includes/init.php';
requireLogin();
requirePermission('inventory.view');

$pageTitle = 'Reorder Management';
$stockModule = new StockManagementModule();
$purchaseOrdersModule = new PurchaseOrdersModule();
$suppliersModule = new SuppliersModule();
$db = getDB();

$days = (int)($_GET['days'] ?? 30);

// Get reorder suggestions
$suggestions = $stockModule->getReorderSuggestions($days);

// Get suppliers for PO generation
$suppliers = $suppliersModule->getSuppliers(['search' => ''], 1000, 0);

include 'templates/header.php';
?>

<div class="row mb-4">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center">
            <h1 class="h3 mb-0">Reorder Suggestions</h1>
            <div class="btn-group" role="group">
                <a href="?days=30" class="btn btn-outline-primary <?php echo $days == 30 ? 'active' : ''; ?>">30 Days</a>
                <a href="?days=60" class="btn btn-outline-primary <?php echo $days == 60 ? 'active' : ''; ?>">60 Days</a>
                <a href="?days=90" class="btn btn-outline-primary <?php echo $days == 90 ? 'active' : ''; ?>">90 Days</a>
            </div>
        </div>
        <p class="text-muted">Based on sales velocity over the last <?php echo $days; ?> days</p>
    </div>
</div>

<div class="row">
    <div class="col-12">
        <div class="card">
            <div class="card-body">
                <?php if (empty($suggestions)): ?>
                    <p class="text-muted text-center mb-0">No reorder suggestions at this time</p>
                <?php else: ?>
                    <form method="POST" action="<?php echo getBaseUrl(); ?>/purchase_orders.php?action=add_from_reorder">
                        <?php echo csrfField(); ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th><input type="checkbox" id="selectAll"></th>
                                        <th>Product</th>
                                        <th>Current Stock</th>
                                        <th>Reorder Level</th>
                                        <th>Suggested Qty</th>
                                        <th>Sales (<?php echo $days; ?>d)</th>
                                        <th>Cost Price</th>
                                        <th>Est. Total</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($suggestions as $suggestion): ?>
                                    <tr>
                                        <td>
                                            <input type="checkbox" name="products[]" value="<?php echo $suggestion['id']; ?>" class="product-checkbox">
                                        </td>
                                        <td>
                                            <strong><?php echo escape($suggestion['name']); ?></strong><br>
                                            <small class="text-muted"><?php echo escape($suggestion['sku']); ?></small>
                                        </td>
                                        <td>
                                            <span class="badge bg-danger"><?php echo $suggestion['stock_quantity']; ?></span>
                                        </td>
                                        <td><?php echo (int)($suggestion['min_stock_level'] ?? $suggestion['reorder_level'] ?? 0); ?></td>
                                        <td>
                                            <input type="number" name="quantities[<?php echo $suggestion['id']; ?>]" 
                                                   value="<?php echo $suggestion['suggested_qty']; ?>" 
                                                   min="1" class="form-control form-control-sm" style="width: 80px;">
                                        </td>
                                        <td><?php echo $suggestion['sales_velocity'] ?? 0; ?></td>
                                        <td><?php echo formatCurrency($suggestion['cost_price']); ?></td>
                                        <td>
                                            <?php 
                                            $estTotal = $suggestion['suggested_qty'] * $suggestion['cost_price'];
                                            echo formatCurrency($estTotal);
                                            ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        
                        <div class="mt-4">
                            <div class="row">
                                <div class="col-md-4">
                                    <label for="supplier_id" class="form-label">Supplier</label>
                                    <select class="form-select" id="supplier_id" name="supplier_id" required>
                                        <option value="">Select Supplier</option>
                                        <?php foreach ($suppliers as $supp): ?>
                                        <option value="<?php echo $supp['id']; ?>"><?php echo escape($supp['name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label for="order_date" class="form-label">Order Date</label>
                                    <input type="date" class="form-control" id="order_date" name="order_date" value="<?php echo date(DATE_FORMAT); ?>" required>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">&nbsp;</label>
                                    <button type="submit" class="btn btn-primary w-100">
                                        <i class="bi bi-file-earmark-text"></i> Generate Purchase Order
                                    </button>
                                </div>
                            </div>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
document.getElementById('selectAll').addEventListener('change', function() {
    const checkboxes = document.querySelectorAll('.product-checkbox');
    checkboxes.forEach(cb => cb.checked = this.checked);
});
</script>

<?php include 'templates/footer.php'; ?>

