<?php
/**
 * Stock Adjustment Page
 * Increase/decrease stock with approval workflow
 */

require_once 'includes/init.php';
requireLogin();
requirePermission('inventory.adjust');

$pageTitle = 'Stock Adjustments';
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
            $productId = (int)$_POST['product_id'];
            $quantityAfter = (int)$_POST['quantity_after'];
            $reason = sanitize($_POST['reason']);
            $reasonCategory = sanitize($_POST['reason_category'] ?? 'other');
            $notes = sanitize($_POST['notes'] ?? '');
            
            $adjustmentId = $stockModule->createStockAdjustment(
                $productId,
                $quantityAfter,
                $reason,
                $reasonCategory,
                $notes
            );
            
            setFlashMessage('success', 'Stock adjustment created successfully.');
            redirect(getBaseUrl() . "/stock_adjustment.php?view={$adjustmentId}");
        } elseif ($action === 'approve' && $id) {
            $approved = isset($_POST['approve']);
            $rejectionReason = isset($_POST['reject']) ? sanitize($_POST['rejection_reason'] ?? '') : null;
            
            $stockModule->approveAdjustment($id, $approved, $rejectionReason);
            setFlashMessage('success', $approved ? 'Adjustment approved.' : 'Adjustment rejected.');
            redirect(getBaseUrl() . '/stock_adjustment.php');
        }
    } catch (Exception $e) {
        setFlashMessage('error', $e->getMessage());
    }
}

// Get data
$adjustment = null;
if ($id) {
    $adjustment = $db->fetchOne(
        "SELECT sa.*, p.name as product_name, p.sku, u1.username as created_by_name, u2.username as approved_by_name 
         FROM stock_adjustments sa 
         INNER JOIN products p ON sa.product_id = p.id 
         LEFT JOIN users u1 ON sa.created_by = u1.id 
         LEFT JOIN users u2 ON sa.approved_by = u2.id 
         WHERE sa.id = ?",
        [$id]
    );
}

// Get adjustments list
$statusFilter = $_GET['status'] ?? 'all';
$where = "1=1";
$params = [];
if ($statusFilter !== 'all') {
    $where .= " AND sa.approval_status = ?";
    $params[] = $statusFilter;
}

$adjustments = $db->fetchAll(
    "SELECT sa.*, p.name as product_name, p.sku, u1.username as created_by_name 
     FROM stock_adjustments sa 
     INNER JOIN products p ON sa.product_id = p.id 
     LEFT JOIN users u1 ON sa.created_by = u1.id 
     WHERE {$where}
     ORDER BY sa.created_at DESC 
     LIMIT 50",
    $params
);

include 'templates/header.php';
?>

<?php if ($action === 'list'): ?>
<div class="row mb-4">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center">
            <h1 class="h3 mb-0">Stock Adjustments</h1>
            <a href="<?php echo getBaseUrl(); ?>/stock_adjustment.php?action=add" class="btn btn-primary">
                <i class="bi bi-plus-circle"></i> New Adjustment
            </a>
        </div>
    </div>
</div>

<!-- Filters -->
<div class="card mb-4">
    <div class="card-body">
        <div class="btn-group" role="group">
            <a href="?status=all" class="btn btn-outline-secondary <?php echo $statusFilter === 'all' ? 'active' : ''; ?>">All</a>
            <a href="?status=pending" class="btn btn-outline-warning <?php echo $statusFilter === 'pending' ? 'active' : ''; ?>">Pending Approval</a>
            <a href="?status=approved" class="btn btn-outline-success <?php echo $statusFilter === 'approved' ? 'active' : ''; ?>">Approved</a>
            <a href="?status=rejected" class="btn btn-outline-danger <?php echo $statusFilter === 'rejected' ? 'active' : ''; ?>">Rejected</a>
        </div>
    </div>
</div>

<!-- Adjustments Table -->
<div class="card">
    <div class="card-body">
        <?php if (empty($adjustments)): ?>
            <p class="text-muted text-center mb-0">No adjustments found</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>Adjustment #</th>
                            <th>Product</th>
                            <th>Type</th>
                            <th>Before</th>
                            <th>After</th>
                            <th>Reason</th>
                            <th>Value Impact</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($adjustments as $adj): ?>
                        <tr>
                            <td><a href="<?php echo getBaseUrl(); ?>/stock_adjustment.php?view=<?php echo $adj['id']; ?>"><?php echo escape($adj['adjustment_number']); ?></a></td>
                            <td><?php echo escape($adj['product_name']); ?> (<?php echo escape($adj['sku']); ?>)</td>
                            <td><?php echo ucfirst($adj['adjustment_type']); ?></td>
                            <td><?php echo $adj['quantity_before']; ?></td>
                            <td><?php echo $adj['quantity_after']; ?></td>
                            <td><?php echo escape($adj['reason']); ?></td>
                            <td><?php echo formatCurrency($adj['adjustment_value']); ?></td>
                            <td><?php echo getStatusBadge($adj['approval_status']); ?></td>
                            <td>
                                <a href="<?php echo getBaseUrl(); ?>/stock_adjustment.php?view=<?php echo $adj['id']; ?>" class="btn btn-sm btn-outline-primary">
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
        <h1 class="h3 mb-0">New Stock Adjustment</h1>
    </div>
</div>

<div class="row">
    <div class="col-lg-8">
        <div class="card">
            <div class="card-body">
                <form method="POST" action="" id="adjustmentForm">
                    <?php echo csrfField(); ?>
                    
                    <div class="mb-3">
                        <label for="product_id" class="form-label">Product <span class="text-danger">*</span></label>
                        <select class="form-select" id="product_id" name="product_id" required onchange="loadProductInfo()">
                            <option value="">Select Product</option>
                            <?php
                            $products = $productsModule->getProducts([], 1000, 0);
                            foreach ($products as $prod):
                            ?>
                            <option value="<?php echo $prod['id']; ?>" data-stock="<?php echo $prod['stock_quantity']; ?>">
                                <?php echo escape($prod['name']); ?> (<?php echo escape($prod['sku']); ?>) - Stock: <?php echo $prod['stock_quantity']; ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Current Stock</label>
                        <input type="text" class="form-control" id="current_stock" readonly>
                    </div>
                    
                    <div class="mb-3">
                        <label for="quantity_after" class="form-label">New Quantity <span class="text-danger">*</span></label>
                        <input type="number" class="form-control" id="quantity_after" name="quantity_after" required min="0" onchange="calculateDifference()">
                        <small class="form-text text-muted" id="difference_text"></small>
                    </div>
                    
                    <div class="mb-3">
                        <label for="reason_category" class="form-label">Reason Category <span class="text-danger">*</span></label>
                        <select class="form-select" id="reason_category" name="reason_category" required>
                            <option value="damaged">Damaged</option>
                            <option value="theft">Theft</option>
                            <option value="discrepancy">Discrepancy</option>
                            <option value="return_to_supplier">Return to Supplier</option>
                            <option value="sample">Sample</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label for="reason" class="form-label">Reason <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="reason" name="reason" required>
                    </div>
                    
                    <div class="mb-3">
                        <label for="notes" class="form-label">Notes</label>
                        <textarea class="form-control" id="notes" name="notes" rows="3"></textarea>
                    </div>
                    
                    <div class="alert alert-info" id="approval_notice" style="display: none;">
                        <i class="bi bi-info-circle"></i> This adjustment requires manager approval (>10% of stock value).
                    </div>
                    
                    <div class="d-flex justify-content-between">
                        <a href="<?php echo getBaseUrl(); ?>/stock_adjustment.php" class="btn btn-secondary">Cancel</a>
                        <button type="submit" class="btn btn-primary">Create Adjustment</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
function loadProductInfo() {
    const select = document.getElementById('product_id');
    const option = select.options[select.selectedIndex];
    const currentStock = option.getAttribute('data-stock') || 0;
    document.getElementById('current_stock').value = currentStock;
    calculateDifference();
}

function calculateDifference() {
    const currentStock = parseInt(document.getElementById('current_stock').value) || 0;
    const quantityAfter = parseInt(document.getElementById('quantity_after').value) || 0;
    const difference = quantityAfter - currentStock;
    
    const diffText = document.getElementById('difference_text');
    if (difference > 0) {
        diffText.textContent = `Increase by ${difference} units`;
        diffText.className = 'form-text text-success';
    } else if (difference < 0) {
        diffText.textContent = `Decrease by ${Math.abs(difference)} units`;
        diffText.className = 'form-text text-danger';
    } else {
        diffText.textContent = 'No change';
        diffText.className = 'form-text text-muted';
    }
}
</script>

<?php elseif ($action === 'view' && $adjustment): ?>
<div class="row mb-4">
    <div class="col-12">
        <h1 class="h3 mb-0">Adjustment: <?php echo escape($adjustment['adjustment_number']); ?></h1>
    </div>
</div>

<div class="row">
    <div class="col-md-8">
        <div class="card mb-4">
            <div class="card-header">Adjustment Details</div>
            <div class="card-body">
                <table class="table">
                    <tr>
                        <th width="200">Adjustment Number</th>
                        <td><?php echo escape($adjustment['adjustment_number']); ?></td>
                    </tr>
                    <tr>
                        <th>Product</th>
                        <td><?php echo escape($adjustment['product_name']); ?> (<?php echo escape($adjustment['sku']); ?>)</td>
                    </tr>
                    <tr>
                        <th>Quantity Before</th>
                        <td><?php echo $adjustment['quantity_before']; ?></td>
                    </tr>
                    <tr>
                        <th>Quantity After</th>
                        <td><?php echo $adjustment['quantity_after']; ?></td>
                    </tr>
                    <tr>
                        <th>Type</th>
                        <td><?php echo ucfirst($adjustment['adjustment_type']); ?></td>
                    </tr>
                    <tr>
                        <th>Reason</th>
                        <td><?php echo escape($adjustment['reason']); ?> (<?php echo ucfirst(str_replace('_', ' ', $adjustment['reason_category'])); ?>)</td>
                    </tr>
                    <tr>
                        <th>Value Impact</th>
                        <td><?php echo formatCurrency($adjustment['adjustment_value']); ?></td>
                    </tr>
                    <tr>
                        <th>Status</th>
                        <td><?php echo getStatusBadge($adjustment['approval_status']); ?></td>
                    </tr>
                    <?php if ($adjustment['approved_by_name']): ?>
                    <tr>
                        <th>Approved By</th>
                        <td><?php echo escape($adjustment['approved_by_name']); ?> on <?php echo formatDateTime($adjustment['approved_at']); ?></td>
                    </tr>
                    <?php endif; ?>
                </table>
            </div>
        </div>
        
        <?php if ($adjustment['approval_status'] === 'pending' && hasPermission('inventory.adjust')): ?>
        <div class="card">
            <div class="card-header">Approve/Reject</div>
            <div class="card-body">
                <form method="POST" action="">
                    <?php echo csrfField(); ?>
                    <div class="mb-3">
                        <label for="rejection_reason" class="form-label">Rejection Reason (if rejecting)</label>
                        <textarea class="form-control" id="rejection_reason" name="rejection_reason" rows="3"></textarea>
                    </div>
                    <div class="d-flex gap-2">
                        <button type="submit" name="approve" value="1" class="btn btn-success">
                            <i class="bi bi-check-circle"></i> Approve
                        </button>
                        <button type="submit" name="reject" value="1" class="btn btn-danger">
                            <i class="bi bi-x-circle"></i> Reject
                        </button>
                    </div>
                </form>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php endif; ?>

<?php include 'templates/footer.php'; ?>

